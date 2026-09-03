<?php
/**
 * What the distributed plugin is allowed to depend on, and to contain.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUILD-SPEC §13 rules 13–15, docs/DECISIONS.md D-0035.
 *
 * The licensing and cloud architecture changed twice before a line of it was
 * written, which is the good case. What this file defends is that it stays
 * decided: these are the failures that rot quietly, cost nothing to check, and
 * are expensive to find in a released package.
 *
 * Three claims:
 *
 * 1. No distributed code depends on a host the architecture has superseded.
 * 2. No cloud or licensing host is hard-coded anywhere. When Phase 19 adds the
 *    endpoint resolver, the canonical base lives in exactly one place, and this
 *    test is what stops a fourth one appearing in a hurry.
 * 3. Nothing secret ships. Not a private key, not an API secret.
 *
 * The third is the one that matters most and the one a person is least likely
 * to notice, because a leaked key in a WordPress plugin is public the moment it
 * is downloaded and cannot be un-leaked.
 */
final class ServiceArchitectureTest extends TestCase {

	/**
	 * Hosts the architecture has superseded and that nothing may require.
	 *
	 * Named here so that documentation describing the old topology stays
	 * readable as history without becoming a live dependency.
	 */
	private const SUPERSEDED_HOSTS = array(
		'license.hakeemify.com',
		'api.hakeemify.com',
		'registry.hakeemify.com',
		'app.hakeemify.com',
	);

	/**
	 * The one host any Hakeemify cloud service may use, if one is used at all.
	 */
	public const CLOUD_HOST = 'cloud.hakeemify.com';

	/**
	 * The base every cloud path must resolve from.
	 */
	public const CLOUD_BASE = 'https://cloud.hakeemify.com';

	/**
	 * No distributed code names a superseded host.
	 *
	 * @return void
	 */
	public function test_no_shipped_code_depends_on_a_superseded_host(): void {
		foreach ( $this->shippedFiles() as $path => $contents ) {
			foreach ( self::SUPERSEDED_HOSTS as $host ) {
				$this->assertStringNotContainsString(
					$host,
					$contents,
					sprintf( '%s depends on %s, which the architecture has superseded (D-0035).', $path, $host )
				);
			}
		}
	}

	/**
	 * No cloud host is hard-coded in shipped code.
	 *
	 * Nothing in the free plugin should reach a cloud service at all, and when
	 * Pro adds one the base URL belongs in a single resolver. This asserts the
	 * first and will be the thing that notices if the second is ever spread
	 * around.
	 *
	 * @return void
	 */
	public function test_no_cloud_host_is_hard_coded(): void {
		foreach ( $this->shippedFiles() as $path => $contents ) {
			$this->assertStringNotContainsString(
				self::CLOUD_HOST,
				$contents,
				sprintf(
					'%s names the cloud host directly. There is no cloud call in the free plugin, and when Pro adds one the base belongs in one endpoint resolver (D-0035).',
					$path
				)
			);
		}
	}

	/**
	 * The free plugin reaches no licensing platform.
	 *
	 * "Free Debloater is fully functional with no Pro, no licensing platform
	 * and no cloud" is only worth as much as the test that would fail if a
	 * licence check appeared in it.
	 *
	 * @return void
	 */
	public function test_the_free_plugin_asks_nobody_for_permission(): void {
		$forbidden = array( 'freemius', 'fs_dynamic_init', 'lemonsqueezy', 'lemon_squeezy', 'edd_' );

		foreach ( $this->shippedFiles() as $path => $contents ) {
			$lowered = strtolower( $contents );

			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$lowered,
					sprintf( '%s reaches a licensing platform. The free plugin asks nobody for permission to run.', $path )
				);
			}
		}
	}

	/**
	 * Nothing secret is in the package.
	 *
	 * A private key committed to a WordPress plugin is public the moment
	 * somebody downloads it, and there is no taking it back. The patterns are
	 * the container formats rather than any particular key, so this keeps
	 * working for keys nobody has generated yet.
	 *
	 * @return void
	 */
	public function test_no_private_key_or_api_secret_is_in_the_package(): void {
		$patterns = array(
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----/' => 'a private key block',
			'/\\bsk_(live|test)_[A-Za-z0-9]{16,}/' => 'a secret API key',
			'/\\bghp_[A-Za-z0-9]{20,}/'            => 'a GitHub token',
			'/[\'"](?:api_secret|client_secret|webhook_secret|signing_key)[\'"]\\s*=>\\s*[\'"][^\'"]{8,}[\'"]/i' => 'an embedded secret',
		);

		foreach ( $this->shippedFiles() as $path => $contents ) {
			foreach ( $patterns as $pattern => $what ) {
				$this->assertSame(
					0,
					preg_match( $pattern, $contents ),
					sprintf( '%s looks like it contains %s. Nothing secret ships (§13 rule 15).', $path, $what )
				);
			}
		}
	}

	/**
	 * Every cloud path the specification names is versioned and product-scoped.
	 *
	 * The specification is the only place these exist right now, which is the
	 * point: the shape is agreed before Phase 19 writes the resolver, and this
	 * fails if a later edit introduces an unversioned endpoint.
	 *
	 * @return void
	 */
	public function test_every_specified_cloud_path_is_versioned(): void {
		$spec = file_get_contents( DEBLOATER_TESTS_ROOT . '/BUILD-SPEC.md' );

		$this->assertIsString( $spec );

		$found = preg_match_all( '#https://' . preg_quote( self::CLOUD_HOST, '#' ) . '(/\\S*)?#', $spec, $matches );

		$this->assertGreaterThan( 0, $found, 'the specification should describe the cloud host it names' );

		foreach ( $matches[1] as $path ) {
			if ( '' === $path ) {
				// The bare base URL, which is what everything else resolves from.
				continue;
			}

			$this->assertMatchesRegularExpression(
				'#^/v[0-9]+/debloater/#',
				(string) $path,
				sprintf( 'Cloud path "%s" is not versioned and product-scoped (D-0035).', (string) $path )
			);
		}
	}

	/**
	 * Schema `$id` values are names, not addresses.
	 *
	 * `debloater.hakeemify.com` appears in every registry schema as its `$id`.
	 * That is a JSON Schema identifier — the validator resolves schemas from
	 * disk and never fetches one — so it is not a host dependency. Asserted
	 * rather than assumed, because the distinction is exactly the kind that gets
	 * lost.
	 *
	 * @return void
	 */
	public function test_schema_ids_are_never_fetched(): void {
		$validator = file_get_contents( DEBLOATER_TESTS_ROOT . '/src/Registry/SchemaValidator.php' );

		$this->assertIsString( $validator );

		foreach ( array( 'wp_remote_', 'curl_', 'http://', 'https://' ) as $fetch ) {
			$this->assertStringNotContainsString(
				$fetch,
				$validator,
				'the schema validator must resolve schemas from disk, never over the network'
			);
		}

		$this->assertStringContainsString(
			'file_get_contents( $path )',
			$validator,
			'and it reads them from a path, which is what makes an $id a name rather than an address'
		);
	}

	/**
	 * Every PHP file that ships to a site, keyed by repository-relative path.
	 *
	 * Tests are excluded: they are not distributed, and this file necessarily
	 * contains every string it forbids.
	 *
	 * @return array<string,string>
	 */
	private function shippedFiles(): array {
		$files = array();

		foreach ( array( 'src', 'runtime-handlers', 'mu-loader', 'admin-ui/src' ) as $directory ) {
			$root = DEBLOATER_TESTS_ROOT . '/' . $directory;

			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) {
					continue;
				}

				$contents = file_get_contents( $file->getPathname() );

				if ( is_string( $contents ) ) {
					$files[ $directory . '/' . $file->getFilename() ] = $contents;
				}
			}
		}

		$this->assertNotSame( array(), $files, 'there should be shipped files to inspect' );

		return $files;
	}
}
