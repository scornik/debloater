<?php
/**
 * What registered, reported: the load report, the status route and the probe.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_REST_Request;
use Debloater\Apply\Lock;
use Debloater\Brand;
use Debloater\Cli\Command;
use Debloater\Contracts\PreviewPlan;
use Debloater\Contracts\ProbeStatus;
use Debloater\Contracts\RunState;
use Debloater\Tests\Integration\Support\RecordingIo;

/**
 * A stored handler that does not register is visible, and fails verification.
 *
 * Before 0.5.0 a skipped handler was invisible everywhere: `load()` returned a
 * number nobody read, `GET /status` counted what was stored, and a run whose
 * changes had not registered committed like any other (`D-0079`). The guard
 * states and skip reasons are pinned as literals because the status route
 * sends them and the probe reads them (P4).
 */
final class RuntimeRegisteredTest extends IntegrationTestCase {

	/**
	 * Handlers registered during a test.
	 *
	 * @var array<int,string>
	 */
	private array $registered = array();

	/**
	 * Tables, a clear lock, and a signed-in administrator to verify as.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The context records the actor when it is built, and set_up() built it.
		$this->plugin->resetServices();
	}

	/**
	 * Undo hooks and fakes.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->unregisterHandlers( $this->registered );

		remove_all_filters( 'pre_http_request' );

		( new Lock() )->forceRelease();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Before `load()` runs, the report claims nothing registered.
	 *
	 * @return void
	 */
	public function test_before_loading_nothing_is_claimed(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$report = $this->plugin->runtime()->report();

		$this->assertSame( 'not_loaded', $report['guard'] );
		$this->assertSame( array( 'Debloater_Handler_Core_Remove_Generator' ), $report['stored'] );
		$this->assertSame( array(), $report['registered'] );
	}

	/**
	 * An empty selection reports that nothing is stored.
	 *
	 * @return void
	 */
	public function test_an_empty_selection_reports_nothing_stored(): void {
		$this->plugin->runtime()->load();

		$report = $this->plugin->runtime()->report();

		$this->assertSame( 'nothing_stored', $report['guard'] );
		$this->assertSame( array(), $report['stored'] );
		$this->assertSame( array(), $report['skipped'] );
	}

	/**
	 * A handler whose file is gone is reported as skipped, and why.
	 *
	 * The `D-0077` case: a tweak removed from the plugin, still named in a
	 * site's stored handler list after upgrading.
	 *
	 * @return void
	 */
	public function test_a_missing_handler_file_is_reported_as_skipped(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );
		$this->plantMissingHandler();

		$this->registered[] = 'core.remove_rsd';
		$this->plugin->runtime()->load();

		$report = $this->plugin->runtime()->report();

		$this->assertSame( 'active', $report['guard'] );
		$this->assertSame( array( 'Debloater_Handler_Core_Remove_Rsd' ), $report['registered'] );
		$this->assertSame(
			array(
				array(
					'class'  => 'Debloater_Handler_Admin_Hide_Update_Nags_Non_Admins',
					'file'   => 'admin-hide-update-nags-non-admins.php',
					'reason' => 'unreadable',
				),
			),
			$report['skipped']
		);
	}

	/**
	 * A name that fails the pattern is reported, and never required.
	 *
	 * @return void
	 */
	public function test_an_invalid_name_is_reported_as_skipped(): void {
		update_option(
			'debloater_runtime',
			array(
				'handlers' => array(
					array(
						'file'   => '../../../wp-config.php',
						'class'  => 'Debloater_Handler_Evil',
						'params' => array(),
					),
				),
			),
			true
		);

		$this->assertSame( 0, $this->plugin->runtime()->load() );

		$report = $this->plugin->runtime()->report();

		$this->assertSame( 'invalid_name', $report['skipped'][0]['reason'] );
		$this->assertSame( array(), $report['registered'] );
	}

	/**
	 * `GET /status` returns stored, registered and skipped for this request.
	 *
	 * @return void
	 */
	public function test_the_status_route_reports_stored_against_registered(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );
		$this->plantMissingHandler();

		$this->registered[] = 'core.remove_rsd';
		$this->plugin->runtime()->load();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . '/status' ) );
		$runtime  = $response->get_data()['runtime'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $runtime['handlers'], 'handlers keeps its old meaning: how many are stored' );
		$this->assertSame( 'active', $runtime['guard'] );
		$this->assertSame(
			array( 'Debloater_Handler_Admin_Hide_Update_Nags_Non_Admins', 'Debloater_Handler_Core_Remove_Rsd' ),
			$runtime['stored']
		);
		$this->assertSame( array( 'Debloater_Handler_Core_Remove_Rsd' ), $runtime['registered'] );
		$this->assertSame( 'unreadable', $runtime['skipped'][0]['reason'] );
	}

	/**
	 * `wp debloater status` prints stored and registered apart, and each skip.
	 *
	 * @return void
	 */
	public function test_the_cli_status_prints_what_did_not_register(): void {
		$this->selectAndGenerate( array( 'core.remove_rsd' => array() ) );
		$this->plantMissingHandler();

		$this->registered[] = 'core.remove_rsd';
		$this->plugin->runtime()->load();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->status( array(), array() );

		$this->assertSame( Command::EXIT_OK, $io->code );
		$this->assertStringContainsString( '1 of 2 stored handlers registered in this request.', $io->output() );
		$this->assertStringContainsString( 'Debloater_Handler_Admin_Hide_Update_Nags_Non_Admins (admin-hide-update-nags-non-admins.php) did not register: unreadable', $io->output() );
		$this->assertStringContainsString( 'Effects: 1 observed, 0 not observed, 0 cannot be observed from a request.', $io->output() );
	}

	/**
	 * Every stored handler registered in the fresh request: PASS.
	 *
	 * @return void
	 */
	public function test_the_probe_passes_when_everything_registered(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus(
			array(
				'guard'      => 'active',
				'stored'     => array( 'Debloater_Handler_Core_Remove_Generator' ),
				'registered' => array( 'Debloater_Handler_Core_Remove_Generator' ),
				'skipped'    => array(),
			)
		);

		$this->assertSame( ProbeStatus::PASS, $this->runtimeProbe()->status );
	}

	/**
	 * A stored handler missing from the fresh request's registrations: FAIL.
	 *
	 * @return void
	 */
	public function test_the_probe_fails_when_a_stored_handler_did_not_register(): void {
		$this->selectAndGenerate(
			array(
				'core.remove_generator' => array(),
				'core.remove_rsd'       => array(),
			)
		);

		$this->answerStatus(
			array(
				'guard'      => 'active',
				'stored'     => array( 'Debloater_Handler_Core_Remove_Generator', 'Debloater_Handler_Core_Remove_Rsd' ),
				'registered' => array( 'Debloater_Handler_Core_Remove_Generator' ),
				'skipped'    => array(
					array(
						'class'  => 'Debloater_Handler_Core_Remove_Rsd',
						'file'   => 'core-remove-rsd.php',
						'reason' => 'unreadable',
					),
				),
			)
		);

		$probe = $this->runtimeProbe();

		$this->assertSame( ProbeStatus::FAIL, $probe->status, $probe->message );
		$this->assertSame( 'Debloater_Handler_Core_Remove_Rsd', $probe->evidence['missing'] );
		$this->assertSame( 'Debloater_Handler_Core_Remove_Rsd: unreadable', $probe->evidence['skipped'] );
		$this->assertStringContainsString( 'Debloater_Handler_Core_Remove_Rsd', $probe->message );
	}

	/**
	 * The kill switch registers nothing, and the probe says the changes are not in effect.
	 *
	 * @return void
	 */
	public function test_the_probe_fails_when_the_guard_registered_nothing(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus(
			array(
				'guard'      => 'disabled',
				'stored'     => array( 'Debloater_Handler_Core_Remove_Generator' ),
				'registered' => array(),
				'skipped'    => array(
					array(
						'class'  => 'Debloater_Handler_Core_Remove_Generator',
						'file'   => 'core-remove-generator.php',
						'reason' => 'guard',
					),
				),
			)
		);

		$probe = $this->runtimeProbe();

		$this->assertSame( ProbeStatus::FAIL, $probe->status );
		$this->assertSame( 'disabled', $probe->evidence['guard'] );
	}

	/**
	 * A status document without `registered` cannot show the changes are in effect.
	 *
	 * The shape `GET /status` had before 0.5.0, which is also what a page cache
	 * serving a stale response would send.
	 *
	 * @return void
	 */
	public function test_the_probe_fails_on_a_status_that_does_not_say_what_registered(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus( array( 'handlers' => 1 ) );

		$this->assertSame( ProbeStatus::FAIL, $this->runtimeProbe()->status );
	}

	/**
	 * A refused status request is unknown, not a failure.
	 *
	 * @return void
	 */
	public function test_the_probe_is_unknown_when_the_status_refuses_the_check(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus( array(), 401 );

		$this->assertSame( ProbeStatus::UNKNOWN, $this->runtimeProbe()->status );
	}

	/**
	 * Nothing stored: PASS without asking.
	 *
	 * @return void
	 */
	public function test_the_probe_passes_when_nothing_is_stored(): void {
		$asked = false;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$asked ) {
				unset( $args );

				if ( 0 === strpos( (string) $url, rest_url( 'debloater/v1/status' ) ) ) {
					$asked = true;
				}

				return $preempt;
			},
			1,
			3
		);

		$this->answerStatus( array() );

		$this->assertSame( ProbeStatus::PASS, $this->runtimeProbe()->status );
		$this->assertFalse( $asked, 'with nothing stored there is nothing to ask about' );
	}

	/**
	 * An apply whose handlers do not register in the next request rolls back.
	 *
	 * The end-to-end half: the probe is wired into the verifier, and its FAIL
	 * reaches the run state machine like any other.
	 *
	 * @return void
	 */
	public function test_an_apply_whose_handlers_did_not_register_rolls_back(): void {
		$this->answerStatus(
			array(
				'guard'      => 'active',
				'stored'     => array( 'Debloater_Handler_Core_Remove_Generator' ),
				'registered' => array(),
				'skipped'    => array(),
			)
		);

		$tweak  = $this->plugin->registry()->tweak( 'core.remove_generator' )->resolve();
		$result = $this->plugin->apply( new PreviewPlan( array( $tweak ) ) );

		$this->assertSame( RunState::ROLLED_BACK, $result->state, (string) $result->error );
		$this->assertSame( array(), $this->plugin->runtime()->storedClasses(), 'rolling back empties the stored selection again' );
	}

	/**
	 * `GET /status` reports, per selected change, whether its effect shows in this request.
	 *
	 * @return void
	 */
	public function test_the_status_route_reports_effects_from_this_request(): void {
		$this->selectAndGenerate(
			array(
				'core.remove_generator'         => array(),
				'core.disable_dashicons_guests' => array(),
			)
		);

		$before = $this->statusEffects();

		$this->assertSame( 'not_observed', $before['core.remove_generator']['status'], 'stored, not yet registered in this request' );
		$this->assertSame( 'wp.generator_tag', $before['core.remove_generator']['fact'] );
		$this->assertTrue( $before['core.remove_generator']['actual'] );
		$this->assertSame( 'unobservable', $before['core.disable_dashicons_guests']['status'] );
		$this->assertNotSame( '', $before['core.disable_dashicons_guests']['reason'] );

		$this->registered = array( 'core.remove_generator', 'core.disable_dashicons_guests' );
		$this->plugin->runtime()->load();

		$this->assertSame( 'observed', $this->statusEffects()['core.remove_generator']['status'] );
	}

	/**
	 * An observable effect that does not show: WARN, "applied but not observed".
	 *
	 * @return void
	 */
	public function test_the_effects_probe_warns_when_a_change_is_not_observed(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus(
			self::allRegistered( 'Debloater_Handler_Core_Remove_Generator' ),
			200,
			array(
				array(
					'tweak'    => 'core.remove_generator',
					'status'   => 'not_observed',
					'fact'     => 'wp.generator_tag',
					'expected' => '= false',
					'actual'   => true,
					'reason'   => '',
				),
			)
		);

		$probe = $this->probeNamed( 'effects_observed' );

		$this->assertSame( ProbeStatus::WARN, $probe->status, $probe->message );
		$this->assertStringContainsString( 'Applied but not observed: core.remove_generator (wp.generator_tag is true, expected = false)', $probe->message );
	}

	/**
	 * Every observable effect shows: PASS.
	 *
	 * @return void
	 */
	public function test_the_effects_probe_passes_when_changes_are_observed(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus(
			self::allRegistered( 'Debloater_Handler_Core_Remove_Generator' ),
			200,
			array(
				array(
					'tweak'    => 'core.remove_generator',
					'status'   => 'observed',
					'fact'     => 'wp.generator_tag',
					'expected' => '= false',
					'actual'   => false,
					'reason'   => '',
				),
			)
		);

		$this->assertSame( ProbeStatus::PASS, $this->probeNamed( 'effects_observed' )->status );
	}

	/**
	 * A status without effects is not a failure of this probe: it is unknown.
	 *
	 * @return void
	 */
	public function test_the_effects_probe_is_unknown_when_effects_are_not_reported(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$this->answerStatus( self::allRegistered( 'Debloater_Handler_Core_Remove_Generator' ) );

		$this->assertSame( ProbeStatus::UNKNOWN, $this->probeNamed( 'effects_observed' )->status );
	}

	/**
	 * Only unobservable changes selected: PASS without asking.
	 *
	 * @return void
	 */
	public function test_the_effects_probe_has_nothing_to_check_for_unobservable_changes(): void {
		$this->selectAndGenerate( array( 'core.disable_dashicons_guests' => array() ) );

		$this->answerStatus( self::allRegistered( 'Debloater_Handler_Core_Disable_Dashicons_Guests' ), 500 );

		$probe = $this->probeNamed( 'effects_observed' );

		$this->assertSame( ProbeStatus::PASS, $probe->status );
		$this->assertSame( 0, $probe->evidence['observable'] );
	}

	/**
	 * The effects section of `GET /status`, keyed by tweak id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function statusEffects(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . Brand::REST_NAMESPACE . '/status' ) );
		$rows     = array();

		foreach ( $response->get_data()['effects'] as $row ) {
			$rows[ $row['tweak'] ] = $row;
		}

		return $rows;
	}

	/**
	 * A runtime section in which the one stored handler registered.
	 *
	 * @param string $handler Handler class.
	 * @return array<string,mixed>
	 */
	private static function allRegistered( string $handler ): array {
		return array(
			'guard'      => 'active',
			'stored'     => array( $handler ),
			'registered' => array( $handler ),
			'skipped'    => array(),
		);
	}

	/**
	 * One probe's result from a verification.
	 *
	 * @param string $name Probe name.
	 * @return \Debloater\Contracts\ProbeResult
	 */
	private function probeNamed( string $name ) {
		foreach ( $this->plugin->verifier()->verify()->probes as $probe ) {
			if ( $name === $probe->probe ) {
				return $probe;
			}
		}

		$this->fail( 'the verifier has no ' . $name . ' probe' );
	}

	/**
	 * The runtime probe's result from a verification.
	 *
	 * @return \Debloater\Contracts\ProbeResult
	 */
	private function runtimeProbe() {
		foreach ( $this->plugin->verifier()->verify()->probes as $probe ) {
			if ( 'runtime_registered' === $probe->probe ) {
				return $probe;
			}
		}

		$this->fail( 'the verifier has no runtime_registered probe' );
	}

	/**
	 * Answer loopback requests as a working site, with this runtime section.
	 *
	 * @param array<string,mixed> $runtime Runtime section of the status document.
	 * @param int                 $status  HTTP status for the status route.
	 * @param array<int,array<string,mixed>>|null $effects Effects section, or null to leave it out.
	 * @return void
	 */
	private function answerStatus( array $runtime, int $status = 200, ?array $effects = null ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $runtime, $status, $effects ) {
				unset( $preempt, $args );

				$code = 200;

				if ( 0 === strpos( (string) $url, rest_url( 'debloater/v1/status' ) ) ) {
					$body = (string) wp_json_encode(
						null === $effects ? array( 'runtime' => $runtime ) : array(
							'runtime' => $runtime,
							'effects' => $effects,
						)
					);
					$code = $status;
				} elseif ( 0 === strpos( (string) $url, rest_url() ) ) {
					$body = (string) wp_json_encode( array( 'name' => 'A site' ) );
				} elseif ( 0 === strpos( (string) $url, wp_login_url() ) ) {
					$body = '<!DOCTYPE html><html><head><title>Log In</title></head><body><form id="loginform"><input name="log"></form></body></html>';
				} elseif ( 0 === strpos( (string) $url, admin_url() ) ) {
					$body = '<!DOCTYPE html><html><head><title>Dashboard</title></head><body><div id="wpadminbar"><ul><li id="wp-admin-bar-my-account"></li></ul></div><div id="adminmenu"></div><div id="wpbody">Howdy</div></body></html>';
				} else {
					$body = '<!DOCTYPE html><html><head><title>A site</title></head><body>Hello</body></html>';
				}

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => $body,
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Add a stored handler whose file does not ship, as `D-0077` left behind.
	 *
	 * @return void
	 */
	private function plantMissingHandler(): void {
		$stored = get_option( 'debloater_runtime' );

		$stored['handlers'][] = array(
			'file'   => 'admin-hide-update-nags-non-admins.php',
			'class'  => 'Debloater_Handler_Admin_Hide_Update_Nags_Non_Admins',
			'params' => array(),
		);

		usort( $stored['handlers'], static fn ( array $a, array $b ): int => strcmp( $a['class'], $b['class'] ) );

		update_option( 'debloater_runtime', $stored, true );
	}
}
