<?php
/**
 * The whole loop from a terminal.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use WP_Error;
use Debloater\Apply\Lock;
use Debloater\Apply\RuntimeLoader;
use Debloater\Cli\Command;
use Debloater\Config\ConfigDocument;
use Debloater\Registry\SchemaValidator;
use Debloater\Tests\Integration\Support\RecordingIo;

/**
 * BUILD-SPEC §17 Phase 7.
 *
 * The commands are driven directly, with a recording terminal, because the exit
 * codes are the contract: 0 worked, 1 refused, 2 rolled back, 3 warnings. A test
 * that let the real `WP_CLI::halt()` run would end the test runner rather than
 * the assertion.
 *
 * The full loop against a real `wp` binary on the fixture site is `npm run
 * test:cli`, which runs `tools/cli-e2e.sh` inside the CLI container. That is
 * where the commands meet actual WP-CLI argument parsing and real loopback HTTP;
 * this is where every branch is covered.
 */
final class CliTest extends IntegrationTestCase {

	/**
	 * A body that looks like a page that rendered.
	 */
	private const GOOD_HTML = '<!DOCTYPE html><html><head><title>A site</title></head><body>Hello</body></html>';

	/**
	 * Prepare the tables and act as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		( new Lock() )->forceRelease();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->plugin->resetServices();
	}

	/**
	 * Clean up files and filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		$files = glob( sys_get_temp_dir() . '/debloater-cli-*.json' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			unlink( $file );
		}

		( new Lock() )->forceRelease();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * `scan --json` produces facts and findings that validate against the
	 * shipped schemas.
	 *
	 * @return void
	 */
	public function test_scan_json_validates_against_the_registry_schemas(): void {
		$io = $this->scanning();

		$this->assertSame( Command::EXIT_OK, $io->code );

		$document = $io->lastDocument();

		$this->assertArrayHasKey( 'run_id', $document );
		$this->assertArrayHasKey( 'facts', $document );
		$this->assertArrayHasKey( 'findings', $document );
		$this->assertArrayHasKey( 'score', $document );

		$this->assertSame(
			array(),
			$this->schema( 'fact' )->validate( $document['facts'] ),
			'The facts a scan reports must satisfy the fact schema.'
		);

		$finding_schema = $this->schema( 'finding' );

		foreach ( $document['findings'] as $finding ) {
			$this->assertSame(
				array(),
				$finding_schema->validate( $finding ),
				sprintf( 'Finding %s does not satisfy the finding schema.', $finding['id'] ?? '?' )
			);
		}
	}

	/**
	 * Reading findings before scanning says so rather than inventing an answer.
	 *
	 * @return void
	 */
	public function test_findings_before_a_scan_is_an_error(): void {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->findings( array(), array() );

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertStringContainsString( 'no scan', $io->output() );
	}

	/**
	 * The risk filter narrows the list, and an unknown risk is refused.
	 *
	 * @return void
	 */
	public function test_findings_can_be_filtered_by_risk(): void {
		$this->plugin->scan();

		$all = new RecordingIo();

		( new Command( $this->plugin, $all ) )->findings( array(), array( 'json' => true ) );

		$low = new RecordingIo();

		( new Command( $this->plugin, $low ) )->findings(
			array(),
			array(
				'json' => true,
				'risk' => 'low',
			) 
		);

		$this->assertSame( Command::EXIT_OK, $low->code );
		$this->assertLessThanOrEqual( $all->lastDocument()['count'], $low->lastDocument()['count'] );

		foreach ( $low->lastDocument()['findings'] as $finding ) {
			$this->assertSame( 'low', $finding['risk'] );
		}

		$bad = new RecordingIo();

		( new Command( $this->plugin, $bad ) )->findings( array(), array( 'risk' => 'catastrophic' ) );

		$this->assertSame( Command::EXIT_ERROR, $bad->code );
		$this->assertStringContainsString( 'not a risk level', $bad->output() );
	}

	/**
	 * `preview` changes nothing at all.
	 *
	 * @return void
	 */
	public function test_preview_changes_nothing(): void {
		$this->plugin->scan();

		$before = $this->plugin->state()->all();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->preview(
			array(),
			array(
				'json'    => true,
				'profile' => 'safe',
			) 
		);

		$this->assertSame( Command::EXIT_OK, $io->code );
		$this->assertArrayHasKey( 'plan', $io->lastDocument() );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
		$this->assertSame( $before['selection'], $this->plugin->state()->all()['selection'] );
	}

	/**
	 * Naming a change that does not exist is refused, rather than silently
	 * planning the ones that do.
	 *
	 * @return void
	 */
	public function test_preview_refuses_an_unknown_tweak(): void {
		$this->plugin->scan();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->preview( array(), array( 'tweaks' => 'core.remove_rsd,core.not_real' ) );

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertStringContainsString( 'core.not_real', $io->output() );
	}

	/**
	 * Applying without --yes does nothing.
	 *
	 * @return void
	 */
	public function test_apply_requires_confirmation(): void {
		$this->plugin->scan();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->apply( array(), array( 'profile' => 'safe' ) );

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertStringContainsString( '--yes', $io->output() );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
		$this->assertSame( array(), $this->plugin->state()->selection() );
	}

	/**
	 * The loop: scan, apply, status, roll back.
	 *
	 * @return void
	 */
	public function test_the_whole_loop(): void {
		$this->serveHealthySite();
		$this->plugin->scan();

		$apply = new RecordingIo();

		( new Command( $this->plugin, $apply ) )->apply(
			array(),
			array(
				'profile' => 'safe',
				'yes'     => true,
			) 
		);

		$this->assertSame( Command::EXIT_OK, $apply->code, $apply->output() );
		$this->assertFileExists( $this->context()->runtimeFile() );
		$this->assertNotSame( array(), $this->plugin->state()->selection() );

		$status = new RecordingIo();

		( new Command( $this->plugin, $status ) )->status( array(), array( 'json' => true ) );

		$document = $status->lastDocument();

		$this->assertSame( Command::EXIT_OK, $status->code );
		$this->assertTrue( $document['runtime']['present'] );
		$this->assertTrue( $document['runtime']['matches_state'] );
		$this->assertGreaterThan( 0, $document['selection_count'] );
		$this->assertNotNull( $document['last_scan'] );

		$rollback = new RecordingIo();

		( new Command( $this->plugin, $rollback ) )->rollback( array(), array( 'yes' => true ) );

		$this->assertSame( Command::EXIT_OK, $rollback->code, $rollback->output() );
		$this->assertSame( array(), $this->plugin->state()->selection() );
		$this->assertFileDoesNotExist( $this->context()->runtimeFile() );
	}

	/**
	 * Rolling back without --yes does nothing.
	 *
	 * @return void
	 */
	public function test_rollback_requires_confirmation(): void {
		$this->serveHealthySite();
		$this->plugin->scan();

		$this->applySafePlan();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->rollback( array(), array() );

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertFileExists( $this->context()->runtimeFile() );
	}

	/**
	 * With nothing to roll back to, the command says so.
	 *
	 * @return void
	 */
	public function test_rollback_with_nothing_to_undo(): void {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->rollback( array(), array( 'yes' => true ) );

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertStringContainsString( 'nothing to roll back', $io->output() );
	}

	/**
	 * An apply on a site that cannot check itself exits 3, not 0.
	 *
	 * @return void
	 */
	public function test_an_apply_that_could_not_be_verified_exits_three(): void {
		$this->blockLoopback();
		$this->plugin->scan();

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->apply(
			array(),
			array(
				'profile' => 'safe',
				'yes'     => true,
			) 
		);

		$this->assertSame( Command::EXIT_WARNINGS, $io->code, $io->output() );
		$this->assertNotSame( array(), $io->warnings );
		$this->assertFileExists( $this->context()->runtimeFile(), 'The change is kept; only the checking failed.' );
	}

	/**
	 * `verify` reports 0 when clean and 3 when it could not check.
	 *
	 * @return void
	 */
	public function test_verify_exit_codes(): void {
		$this->serveHealthySite();

		$clean = new RecordingIo();

		( new Command( $this->plugin, $clean ) )->verify( array(), array( 'json' => true ) );

		$this->assertSame( Command::EXIT_OK, $clean->code, $clean->output() );
		$this->assertSame( 'PASS', $clean->lastDocument()['status'] );

		remove_all_filters( 'pre_http_request' );
		$this->blockLoopback();

		$warned = new RecordingIo();

		( new Command( $this->plugin, $warned ) )->verify( array(), array( 'json' => true ) );

		$this->assertSame( Command::EXIT_WARNINGS, $warned->code );
		$this->assertSame( 'WARN', $warned->lastDocument()['status'] );
	}

	/**
	 * Recovery points can be listed, shown and deleted.
	 *
	 * @return void
	 */
	public function test_snapshots_list_show_and_delete(): void {
		$this->serveHealthySite();
		$this->plugin->scan();
		$this->applySafePlan();

		$list = new RecordingIo();

		( new Command( $this->plugin, $list ) )->snapshots( array( 'list' ), array( 'json' => true ) );

		$this->assertSame( Command::EXIT_OK, $list->code );
		$this->assertGreaterThan( 0, $list->lastDocument()['count'] );

		$id = (int) $list->lastDocument()['snapshots'][0]['id'];

		$show = new RecordingIo();

		( new Command( $this->plugin, $show ) )->snapshots( array( 'show', (string) $id ), array() );

		$this->assertSame( Command::EXIT_OK, $show->code );
		$this->assertSame( $id, (int) $show->lastDocument()['id'] );

		$unconfirmed = new RecordingIo();

		( new Command( $this->plugin, $unconfirmed ) )->snapshots( array( 'delete', (string) $id ), array() );

		$this->assertSame( Command::EXIT_ERROR, $unconfirmed->code );
		$this->assertNotNull( $this->plugin->snapshots()->find( $id ) );

		$deleted = new RecordingIo();

		( new Command( $this->plugin, $deleted ) )->snapshots( array( 'delete', (string) $id ), array( 'yes' => true ) );

		$this->assertSame( Command::EXIT_OK, $deleted->code );
		$this->assertNull( $this->plugin->snapshots()->find( $id ) );

		$missing = new RecordingIo();

		( new Command( $this->plugin, $missing ) )->snapshots( array( 'show', '999999' ), array() );

		$this->assertSame( Command::EXIT_ERROR, $missing->code );
	}

	/**
	 * Export writes a document that satisfies the configuration schema, and
	 * import reads it back.
	 *
	 * @return void
	 */
	public function test_export_and_import_round_trip(): void {
		$this->serveHealthySite();
		$this->plugin->scan();
		$this->applySafePlan();

		$selection = $this->plugin->state()->selection();
		$path      = sys_get_temp_dir() . '/debloater-cli-export.json';

		$export = new RecordingIo();

		( new Command( $this->plugin, $export ) )->export( array(), array( 'file' => $path ) );

		$this->assertSame( Command::EXIT_OK, $export->code, $export->output() );
		$this->assertFileExists( $path );

		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $decoded );
		$this->assertSame(
			array(),
			$this->plugin->configSchema()->validate( $decoded ),
			'An exported configuration must satisfy the schema import validates against.'
		);

		$document = ConfigDocument::fromArray( $decoded );

		$this->assertSame( array_keys( $selection ), array_keys( $document->selection ) );
		$this->assertSame( array(), $document->problems( $this->plugin->registry() ) );

		// Put the site back to nothing, then import.
		$this->plugin->rollback( (int) $this->plugin->runs()->recent( 1 )[0]->id );

		$this->assertSame( array(), $this->plugin->state()->selection() );

		$check = new RecordingIo();

		( new Command( $this->plugin, $check ) )->import( array( $path ), array() );

		$this->assertSame( Command::EXIT_OK, $check->code, $check->output() );
		$this->assertSame( array(), $this->plugin->state()->selection(), 'Import without --apply changes nothing.' );

		$apply = new RecordingIo();

		( new Command( $this->plugin, $apply ) )->import(
			array( $path ),
			array(
				'apply' => true,
				'yes'   => true,
			) 
		);

		$this->assertSame( Command::EXIT_OK, $apply->code, $apply->output() );
		$this->assertSame( array_keys( $selection ), array_keys( $this->plugin->state()->selection() ) );
	}

	/**
	 * Import refuses anything that is not a configuration document, before it
	 * reads a single value out of it.
	 *
	 * @return void
	 */
	public function test_import_refuses_a_file_that_is_not_a_configuration(): void {
		$cases = array(
			'not json at all'            => 'this is not json',
			'json but not a document'    => '{"hello":"world"}',
			'wrong schema version'       => '{"schema_version":99,"plugin_version":"0.1.0","intent_profile":{"site_type":"blog","priority":"balanced"},"selection":{}}',
			'selection is not an object' => '{"schema_version":1,"plugin_version":"0.1.0","intent_profile":{"site_type":"blog","priority":"balanced"},"selection":[1,2]}',
			'bad tweak id'               => '{"schema_version":1,"plugin_version":"0.1.0","intent_profile":{"site_type":"blog","priority":"balanced"},"selection":{"DROP TABLE":{}}}',
		);

		$index = 0;

		foreach ( $cases as $label => $contents ) {
			$path = sys_get_temp_dir() . '/debloater-cli-bad-' . $index . '.json';
			++$index;

			file_put_contents( $path, $contents );

			$io = new RecordingIo();

			( new Command( $this->plugin, $io ) )->import(
				array( $path ),
				array(
					'apply' => true,
					'yes'   => true,
				) 
			);

			$this->assertSame( Command::EXIT_ERROR, $io->code, $label );
			$this->assertSame( array(), $this->plugin->state()->selection(), $label );

			unlink( $path );
		}
	}

	/**
	 * A configuration naming a change this version does not have is reported,
	 * and the rest of the file still works.
	 *
	 * @return void
	 */
	public function test_import_warns_about_changes_it_does_not_know(): void {
		$this->serveHealthySite();
		$this->plugin->scan();

		$path = sys_get_temp_dir() . '/debloater-cli-partial.json';

		file_put_contents(
			$path,
			(string) wp_json_encode(
				array(
					'schema_version' => 1,
					'plugin_version' => '0.1.0',
					'intent_profile' => array(
						'site_type' => 'blog',
						'priority'  => 'balanced',
					),
					'selection'      => array(
						'core.remove_rsd'      => new \stdClass(),
						'core.from_the_future' => new \stdClass(),
					),
				)
			)
		);

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->import(
			array( $path ),
			array(
				'apply' => true,
				'yes'   => true,
			) 
		);

		$this->assertSame( Command::EXIT_OK, $io->code, $io->output() );
		$this->assertStringContainsString( 'core.from_the_future', $io->output() );
		$this->assertArrayHasKey( 'core.remove_rsd', $this->plugin->state()->selection() );
		$this->assertArrayNotHasKey( 'core.from_the_future', $this->plugin->state()->selection() );

		unlink( $path );
	}

	/**
	 * Import without --yes does not apply.
	 *
	 * @return void
	 */
	public function test_import_apply_requires_confirmation(): void {
		$this->plugin->scan();

		$path = sys_get_temp_dir() . '/debloater-cli-confirm.json';

		file_put_contents(
			$path,
			(string) wp_json_encode(
				array(
					'schema_version' => 1,
					'plugin_version' => '0.1.0',
					'intent_profile' => array(
						'site_type' => 'blog',
						'priority'  => 'balanced',
					),
					'selection'      => array( 'core.remove_rsd' => new \stdClass() ),
				)
			)
		);

		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->import( array( $path ), array( 'apply' => true ) );

		$this->assertSame( Command::EXIT_ERROR, $io->code );
		$this->assertSame( array(), $this->plugin->state()->selection() );

		unlink( $path );
	}

	/**
	 * `status` reports what is actually there, before anything has happened.
	 *
	 * @return void
	 */
	public function test_status_on_an_untouched_site(): void {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->status( array(), array( 'json' => true ) );

		$document = $io->lastDocument();

		$this->assertSame( Command::EXIT_OK, $io->code );
		$this->assertSame( 0, $document['selection_count'] );
		$this->assertFalse( $document['runtime']['present'] );
		$this->assertTrue( $document['runtime']['matches_state'] );
		$this->assertNull( $document['last_scan'] );
		$this->assertFalse( $document['lock']['held'] );
		$this->assertContains(
			$document['loader']['mode'],
			array( RuntimeLoader::MODE_MU_PLUGIN, RuntimeLoader::MODE_FALLBACK, RuntimeLoader::MODE_NONE )
		);
	}

	/**
	 * Every run and journal row the CLI creates is attributed to `cli`, not to
	 * whoever happened to be signed in.
	 *
	 * @return void
	 */
	public function test_the_actor_is_recorded(): void {
		$this->serveHealthySite();
		$this->plugin->scan();

		$run = $this->plugin->runs()->recent( 1 )[0];

		$this->assertMatchesRegularExpression(
			'/^(cli|cron|system|user:[1-9][0-9]*)$/',
			$run->actor,
			'A run must record who asked for it.'
		);
	}

	/**
	 * Run a scan through the command and hand back what it said.
	 *
	 * @return RecordingIo
	 */
	private function scanning(): RecordingIo {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->scan( array(), array( 'json' => true ) );

		return $io;
	}

	/**
	 * Apply the safe plan through the command.
	 *
	 * @return void
	 */
	private function applySafePlan(): void {
		$io = new RecordingIo();

		( new Command( $this->plugin, $io ) )->apply(
			array(),
			array(
				'profile' => 'safe',
				'yes'     => true,
			) 
		);

		$this->assertContains(
			$io->code,
			array( Command::EXIT_OK, Command::EXIT_WARNINGS ),
			$io->output()
		);
	}

	/**
	 * A schema validator for one of the shipped schemas.
	 *
	 * @param string $name Schema name without the suffix.
	 * @return SchemaValidator
	 */
	private function schema( string $name ): SchemaValidator {
		return SchemaValidator::fromFile( DEBLOATER_TESTS_ROOT . '/registry/schemas/' . $name . '.schema.json' );
	}

	/**
	 * Answer every verification request as a working site would.
	 *
	 * @return void
	 */
	private function serveHealthySite(): void {
		$plugin = $this->plugin;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $plugin ) {
				unset( $preempt, $args );

				$body = self::GOOD_HTML;

				if ( 0 === strpos( $url, rest_url( 'debloater/v1/status' ) ) ) {
					$body = (string) wp_json_encode(
						array(
							'runtime' => array( 'hash' => $plugin->state()->runtimeHash() ),
							'loader'  => array( 'mode' => RuntimeLoader::MODE_MU_PLUGIN ),
						)
					);
				} elseif ( 0 === strpos( $url, rest_url() ) ) {
					$body = (string) wp_json_encode( array( 'name' => 'A site' ) );
				} elseif ( 0 === strpos( $url, wp_login_url() ) ) {
					$body = '<html><head><title>Log In</title></head><body><form id="loginform"></form></body></html>';
				} elseif ( 0 === strpos( $url, admin_url() ) ) {
					$body = '<html><head><title>Dashboard</title></head><body>'
						. '<div id="adminmenu"></div><div id="wpbody"></div></body></html>';
				}

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
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
	 * Make every outbound request fail the way a blocked loopback does.
	 *
	 * @return void
	 */
	private function blockLoopback(): void {
		add_filter(
			'pre_http_request',
			static fn (): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ),
			10,
			3
		);
	}
}
