<?php
/**
 * The analyzer against a real site, and the two REST routes.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration;

use WP_REST_Request;
use WPDebloat\Analyze\Score;
use WPDebloat\Brand;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Finding;
use WPDebloat\Registry\SchemaValidator;

/**
 * BUILD-SPEC §17 Phase 3.
 *
 * The unit tests cover the rules against fixtures. These cover the parts only a
 * real site can show: that the findings survive being written to and read from
 * the database, and that the endpoints are closed to everyone who should not
 * see them.
 */
final class AnalyzerTest extends IntegrationTestCase {

	/**
	 * Set up the REST server and the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->plugin->schema()->ensure();

		$wp_rest_server = new \WP_REST_Server();

		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * A scan of a real site produces findings, and every one validates against
	 * the finding schema.
	 *
	 * @return void
	 */
	public function test_a_real_scan_produces_schema_valid_findings(): void {
		$run = $this->plugin->scan();

		$analysis = $run->payload['analysis'];

		$this->assertNotEmpty( $analysis['findings'] );

		$validator = SchemaValidator::fromFile( WPDEBLOAT_TESTS_ROOT . '/registry/schemas/finding.schema.json' );

		foreach ( $analysis['findings'] as $finding ) {
			$violations = $validator->validate( $finding );

			$this->assertSame(
				array(),
				$violations,
				$finding['id'] . ' failed schema validation: ' . implode( '; ', array_map( 'strval', $violations ) )
			);
		}
	}

	/**
	 * Findings and score survive the round trip through the database.
	 *
	 * Compared as contracts rather than as raw arrays, deliberately. JSON has one
	 * number type, so a float that happens to be whole — an impact of 1.0 —
	 * comes back as an int. The contracts already handle that (Assert::float
	 * widens an int, because JSON cannot spell 1.0), and comparing the rebuilt
	 * Findings tests what actually matters: that nothing was lost.
	 *
	 * @return void
	 */
	public function test_findings_survive_storage(): void {
		$run      = $this->plugin->scan();
		$reloaded = $this->plugin->runs()->find( (int) $run->id );

		$this->assertNotNull( $reloaded );
		$this->assertSame(
			$run->payload['analysis']['score'],
			$reloaded->payload['analysis']['score'],
			'the score is all integers and strings, so it must match exactly'
		);

		$written = $run->payload['analysis']['findings'];
		$read    = $reloaded->payload['analysis']['findings'];

		$this->assertCount( count( $written ), $read );

		foreach ( $written as $index => $data ) {
			$this->assertEquals(
				Finding::fromArray( $data ),
				Finding::fromArray( $read[ $index ] ),
				'finding ' . $data['id'] . ' did not survive storage'
			);
		}
	}

	/**
	 * Facts and findings are stored together, so a finding is always readable
	 * next to the facts it was drawn from.
	 *
	 * @return void
	 */
	public function test_facts_and_findings_share_a_run(): void {
		$run = $this->plugin->scan();

		$this->assertArrayHasKey( 'facts', $run->payload );
		$this->assertArrayHasKey( 'analysis', $run->payload );
		$this->assertGreaterThan( 0, $run->facts()->count() );
	}

	/**
	 * The default test site is close to a fresh install, so the core-output
	 * findings all fire.
	 *
	 * @return void
	 */
	public function test_a_default_install_produces_the_expected_findings(): void {
		$analysis = $this->plugin->scan()->payload['analysis'];

		$ids = array_column( $analysis['findings'], 'id' );

		foreach ( array( 'wp.generator.exposed', 'wp.rsd.exposed', 'wp.shortlink.exposed', 'wp.emojis.loaded', 'wp.embeds.enabled', 'wp.xmlrpc.enabled' ) as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}

	/**
	 * Applying a tweak removes its finding from the next scan, so the same
	 * suggestion is never made twice.
	 *
	 * @return void
	 */
	public function test_an_applied_tweak_stops_being_recommended(): void {
		$before = array_column( $this->plugin->scan()->payload['analysis']['findings'], 'id' );

		$this->assertContains( 'wp.generator.exposed', $before );

		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );
		$this->loadRuntime();

		$after = array_column( $this->plugin->scan()->payload['analysis']['findings'], 'id' );

		$this->assertNotContains( 'wp.generator.exposed', $after );

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * The score is recorded with its rubric version, so a number can be traced
	 * back to the rules that produced it.
	 *
	 * @return void
	 */
	public function test_the_score_records_its_rubric_version(): void {
		$score = $this->plugin->scan()->payload['analysis']['score'];

		$this->assertSame( Score::RUBRIC_VERSION, $score['rubric_version'] );
		$this->assertGreaterThanOrEqual( 0, $score['headline'] );
		$this->assertLessThanOrEqual( 100, $score['headline'] );
	}

	/**
	 * Every finding names a tweak the registry actually has.
	 *
	 * @return void
	 */
	public function test_every_recommendation_names_a_real_tweak(): void {
		$registry = $this->plugin->registry();

		foreach ( $this->plugin->scan()->payload['analysis']['findings'] as $finding ) {
			if ( empty( $finding['recommendation'] ) ) {
				continue;
			}

			$this->assertTrue(
				$registry->has( $finding['recommendation']['tweak_id'] ),
				$finding['id'] . ' recommends a tweak that is not in the registry'
			);
		}
	}

	/**
	 * POST /scan is closed to anyone without the capability.
	 *
	 * @return void
	 */
	public function test_scan_requires_the_capability(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->post( '/scan' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->post( '/scan' )->get_status() );
	}

	/**
	 * GET /findings is closed too: a scan describes the site's configuration in
	 * detail.
	 *
	 * @return void
	 */
	public function test_findings_requires_the_capability(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get( '/findings' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->get( '/findings' )->get_status() );
	}

	/**
	 * An administrator can scan, and the response describes the run.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_scan(): void {
		$this->asAdministrator();

		$response = $this->post( '/scan' );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();

		$this->assertIsInt( $data['run_id'] );
		$this->assertGreaterThan( 0, $data['facts_count'] );
		$this->assertNotEmpty( $data['analysis']['findings'] );
	}

	/**
	 * Before any scan, the findings endpoint says so rather than returning an
	 * empty list that reads like good news.
	 *
	 * @return void
	 */
	public function test_findings_distinguishes_unscanned_from_clean(): void {
		$this->asAdministrator();

		$data = $this->get( '/findings' )->get_data();

		$this->assertFalse( $data['scanned'] );
		$this->assertSame( array(), $data['findings'] );
		$this->assertNotEmpty( $data['message'] );
	}

	/**
	 * After a scan, the findings endpoint returns them with the score.
	 *
	 * @return void
	 */
	public function test_findings_returns_the_recorded_scan(): void {
		$this->asAdministrator();

		$run  = $this->plugin->scan();
		$data = $this->get( '/findings' )->get_data();

		$this->assertTrue( $data['scanned'] );
		$this->assertSame( $run->id, $data['run_id'] );
		$this->assertSame( count( $run->payload['analysis']['findings'] ), $data['total'] );
		$this->assertArrayHasKey( 'headline', $data['score'] );
	}

	/**
	 * The filters narrow the list.
	 *
	 * @return void
	 */
	public function test_findings_can_be_filtered(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$safe = $this->get( '/findings', array( 'risk' => 'safe' ) )->get_data();

		foreach ( $safe['findings'] as $finding ) {
			$this->assertSame( 'safe', $finding['risk'] );
		}

		$info = $this->get( '/findings', array( 'decision' => Decision::INFO->value ) )->get_data();

		foreach ( $info['findings'] as $finding ) {
			$this->assertSame( 'info', $finding['decision'] );
		}

		$all = $this->get( '/findings' )->get_data();

		$this->assertGreaterThan( 0, $safe['total'], 'a default install has safe findings' );
		$this->assertGreaterThan( 0, $info['total'], 'a default install has informational findings' );
		$this->assertLessThan( $all['total'], $safe['total'], 'a filter must actually narrow the list' );

		// Every finding carries exactly one decision, so the three buckets
		// account for the whole list with nothing double-counted.
		$by_decision = 0;

		foreach ( array( 'recommend', 'dont_touch', 'info' ) as $decision ) {
			$by_decision += $this->get( '/findings', array( 'decision' => $decision ) )->get_data()['total'];
		}

		$this->assertSame( $all['total'], $by_decision );
	}

	/**
	 * An unknown filter value is rejected before the route runs
	 * (BUILD-SPEC §13 rule 3).
	 *
	 * @return void
	 */
	public function test_an_unknown_filter_value_is_rejected(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$this->assertSame( 400, $this->get( '/findings', array( 'risk' => 'catastrophic' ) )->get_status() );
		$this->assertSame( 400, $this->get( '/findings', array( 'decision' => 'maybe' ) )->get_status() );
	}

	/**
	 * The findings response carries nothing sensitive.
	 *
	 * @return void
	 */
	public function test_findings_exposes_nothing_sensitive(): void {
		$this->asAdministrator();
		$this->plugin->scan();

		$encoded = (string) wp_json_encode( $this->get( '/findings' )->get_data() );

		$this->assertStringNotContainsString( ABSPATH, $encoded, 'absolute paths must not leak through the API' );

		// The high-entropy secrets, rather than DB_PASSWORD or DB_NAME: on a test
		// install those are "password" and "WordPress", both of which occur in
		// ordinary prose — the XML-RPC finding legitimately discusses passwords.
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'NONCE_SALT' ) as $secret ) {
			if ( defined( $secret ) && is_string( constant( $secret ) ) && strlen( (string) constant( $secret ) ) > 16 ) {
				$this->assertStringNotContainsString( (string) constant( $secret ), $encoded, $secret );
			}
		}
	}

	/**
	 * Become an administrator.
	 *
	 * @return void
	 */
	private function asAdministrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatch a GET request to one of our routes.
	 *
	 * @param string              $path  Route path.
	 * @param array<string,mixed> $query Query parameters.
	 * @return \WP_REST_Response
	 */
	private function get( string $path, array $query = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . $path );

		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Dispatch a POST request to one of our routes.
	 *
	 * @param string $path Route path.
	 * @return \WP_REST_Response
	 */
	private function post( string $path ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/' . Brand::REST_NAMESPACE . $path ) );
	}
}
