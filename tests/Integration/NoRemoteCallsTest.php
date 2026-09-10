<?php
/**
 * Nothing leaves this site.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

/**
 * wordpress.org review round 2: "Calling files remotely".
 *
 * The registry used to be fetchable from
 * `raw.githubusercontent.com/scornik/debloater-registry`. It is not any more —
 * the vendored snapshot is the only registry the free plugin has, and a newer
 * one arrives with a plugin release (`D-0073`).
 *
 * That is easy to say and easy to undo by accident, which is what this file is
 * for. It runs the whole pipeline — scan, analyse, preview, apply, verify,
 * roll back — with a spy on `pre_http_request`, and fails if any request went
 * to a host that is not this site's.
 *
 * ## What it deliberately does not forbid
 *
 * Requests to the site's own host. Verification loads the site's own pages as
 * the acting user, which is the whole point of it, and the asset scan samples
 * the site's own pages. Those are loopback, they are the reason
 * `Verify\HttpClient` exists, and forbidding them would forbid the feature.
 *
 * The one remaining opt-in outbound call is the plugin release-date check
 * against `api.wordpress.org`, which is WordPress's own API, is off unless a
 * scan is asked for it explicitly, and is disclosed in readme.txt. It is off in
 * everything below, which is the default a site gets.
 */
final class NoRemoteCallsTest extends IntegrationTestCase {

	/**
	 * Every request the spy saw.
	 *
	 * Public because the filter is a static closure capturing `$this`, matching
	 * the other suites in this directory.
	 *
	 * @var array<int,string>
	 */
	public array $requested = array();

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requested = array();
		$this->plugin->schema()->ensure();
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * A whole cycle reaches nothing but this site.
	 *
	 * @return void
	 */
	public function test_the_pipeline_calls_no_other_host(): void {
		$this->spy();

		$run = $this->plugin->scan();

		$this->plugin->findingsOf( $run );

		$preview = $this->plugin->preview( 'safe' );

		if ( null !== $preview ) {
			$applied = $this->plugin->apply( $preview->plan );

			$this->plugin->rollback( (int) $applied->run_id );
		}

		// The spy has to have seen something, or "no off-site requests" is a
		// sentence about a pipeline that made no requests at all and the
		// assertion below is decorative (**P3**).
		$this->assertNotSame(
			array(),
			$this->requested,
			'the spy recorded nothing, so this test proves nothing'
		);

		$this->assertSame(
			array(),
			$this->offsite(),
			"The free plugin called a host that is not this site:\n" . implode( "\n", $this->offsite() )
		);
	}

	/**
	 * There is no code left that could fetch a registry.
	 *
	 * The pipeline test above only proves the paths it walks. This one is about
	 * the paths it does not: a fetch that survives behind a flag nobody sets is
	 * still a fetch, and the reviewer reads the source rather than running it.
	 *
	 * Literals, not class names — the classes are gone, so naming them would
	 * compare nothing with nothing (**P4**).
	 *
	 * @return void
	 */
	public function test_no_source_file_names_a_registry_origin(): void {
		$offenders = array();

		$needles = array(
			'raw.githubusercontent.com',
			'RegistryUpdater',
			'RegistryOrigin',
			'debloater_registry_origin',
		);

		foreach ( $this->sourceFiles() as $relative => $source ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $source, $needle ) ) {
					$offenders[] = $relative . ' contains ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $offenders, implode( "\n", $offenders ) );
	}

	/**
	 * Every shipped PHP file, by relative path.
	 *
	 * @return array<string,string>
	 */
	private function sourceFiles(): array {
		$root  = DEBLOATER_TESTS_ROOT;
		$files = array();

		foreach ( array( 'src', 'runtime-handlers' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root . '/' . $directory, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$relative           = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
					$files[ $relative ] = (string) file_get_contents( $file->getPathname() );
				}
			}
		}

		return $files;
	}

	/**
	 * The requests that went somewhere other than this site.
	 *
	 * @return array<int,string>
	 */
	private function offsite(): array {
		$ours = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return array_values(
			array_filter(
				$this->requested,
				static function ( string $url ) use ( $ours ): bool {
					$host = wp_parse_url( $url, PHP_URL_HOST );

					return is_string( $host ) && '' !== $host && strtolower( $host ) !== $ours;
				}
			)
		);
	}

	/**
	 * Record every outbound request and answer it locally.
	 *
	 * @return void
	 */
	private function spy(): void {
		$test = $this;

		add_filter(
			'pre_http_request',
			/**
			 * @param mixed               $preempt Short-circuit value.
			 * @param array<string,mixed> $args    Request arguments.
			 * @param string              $url     Requested URL.
			 * @return array<string,mixed>
			 */
			static function ( $preempt, array $args, string $url ) use ( $test ) {
				unset( $preempt, $args );

				$test->requested[] = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => '<html><head><title>x</title></head><body><div id="wpadminbar"><ul>'
						. '<li id="wp-admin-bar-my-account"></li></ul></div><div id="adminmenu"></div>'
						. '<div id="wpbody"></div></body></html>',
				);
			},
			10,
			3
		);
	}
}
