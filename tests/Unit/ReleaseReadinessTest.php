<?php
/**
 * What has to be true before this can be uploaded anywhere.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Debloater\Brand;

/**
 * BUILD-SPEC §17 Phase 18.
 *
 * Release checklists rot. Somebody bumps a version in one file, the readme
 * keeps the old one, and wordpress.org serves a plugin whose stable tag points
 * at a release that does not exist. None of that is caught by testing the code,
 * because none of it is code — which is exactly why it belongs in a test rather
 * than in a document somebody is supposed to remember to read.
 */
final class ReleaseReadinessTest extends TestCase {

	/**
	 * Identifiers that must be defined in exactly one place.
	 *
	 * Not the product *name* — that appears inside sentences, where it belongs
	 * and where a placeholder would read worse. These are the machine-readable
	 * ones, where a stray literal is not a style problem but a bug: a
	 * mismatched option name looks like a setting that will not save, and
	 * nothing anywhere reports an error.
	 *
	 * @var array<int,string>
	 */
	private const IDENTIFIERS = array(
		'debloater_manage',
		'debloater/v1',
		'debloater_state',
		'debloater_lock',
	);

	/**
	 * The header fields wordpress.org requires, and what they must say.
	 *
	 * @return void
	 */
	public function test_the_plugin_header_is_complete(): void {
		$header = $this->file( 'debloater.php' );

		$expected = array(
			'Plugin Name'       => Brand::fullName(),
			'Requires at least' => '6.5',
			'Requires PHP'      => '8.1',
			'License'           => 'GPL-2.0-or-later',
			'Text Domain'       => Brand::TEXT_DOMAIN,
			'Domain Path'       => '/languages',
		);

		foreach ( $expected as $field => $value ) {
			$this->assertSame(
				$value,
				$this->headerField( $header, $field ),
				sprintf( 'The "%s" header must say "%s".', $field, $value )
			);
		}

		// Present, without this test caring what they say.
		foreach ( array( 'Description', 'Version', 'Author', 'License URI', 'Plugin URI' ) as $field ) {
			$this->assertNotSame(
				'',
				$this->headerField( $header, $field ),
				sprintf( 'The "%s" header must not be empty.', $field )
			);
		}
	}

	/**
	 * Neither the name nor the slug may contain a restricted term.
	 *
	 * This is the check that caused the rename (docs/DECISIONS.md D-0047).
	 * wordpress.org's Readme Validator and Plugin Check refuse a name or slug
	 * carrying "wp", and the refusal happens at review — so it is worth failing
	 * here, where it costs a test run, rather than there, where it costs a
	 * submission.
	 *
	 * @return void
	 */
	public function test_the_name_and_slug_carry_no_restricted_term(): void {
		$restricted = array( 'wp', 'wordpress', 'plugin', 'woocommerce' );

		// The slug is the strict case: no restricted term anywhere in it.
		foreach ( $restricted as $term ) {
			$this->assertStringNotContainsString(
				$term,
				strtolower( Brand::SLUG ),
				sprintf( 'wordpress.org refuses a slug containing "%s".', $term )
			);
		}

		// The short name is held to the same rule, because it is what the slug
		// is derived from and what the menu shows.
		foreach ( $restricted as $term ) {
			$this->assertStringNotContainsString(
				$term,
				strtolower( Brand::NAME ),
				sprintf( 'wordpress.org refuses a plugin name containing "%s".', $term )
			);
		}

		// "WordPress" is allowed in a display title, and only there — the
		// tagline is where this product says what it is for.
		$this->assertStringContainsString( 'WordPress', Brand::TAGLINE );

		// The text domain must equal the slug: wordpress.org derives one from
		// the other and serves translations against it.
		$this->assertSame( Brand::SLUG, Brand::TEXT_DOMAIN );

		// And the full title is composed, not typed.
		$this->assertSame(
			Brand::NAME . ' – ' . Brand::TAGLINE,
			Brand::fullName()
		);
	}

	/**
	 * One version, said the same way in all four places that say it.
	 *
	 * @return void
	 */
	public function test_every_file_agrees_on_the_version(): void {
		$plugin  = $this->file( 'debloater.php' );
		$version = $this->headerField( $plugin, 'Version' );

		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			$version,
			'The version must be three numbers, because wordpress.org sorts them as such.'
		);

		// The constant the plugin reports at runtime.
		$this->assertMatchesRegularExpression(
			"/const DEBLOATER_VERSION\s*=\s*'" . preg_quote( $version, '/' ) . "'/",
			$plugin,
			'DEBLOATER_VERSION must match the header.'
		);

		// The stable tag wordpress.org actually serves.
		$this->assertSame(
			$version,
			$this->readmeField( 'Stable tag' ),
			'readme.txt Stable tag must match the plugin header.'
		);

		// And the npm package, so `npm version` cannot drift away quietly.
		$package = json_decode( $this->file( 'package.json' ), true );

		$this->assertIsArray( $package );
		$this->assertSame( $version, $package['version'] );

		// The changelog has to mention it, or the release has no notes.
		$this->assertStringContainsString(
			$version,
			$this->file( 'CHANGELOG.md' ),
			'CHANGELOG.md does not mention the version being released.'
		);

		$this->assertStringContainsString(
			'= ' . $version . ' =',
			$this->file( 'readme.txt' ),
			'readme.txt has no changelog entry for this version.'
		);
	}

	/**
	 * readme.txt says what wordpress.org needs it to say.
	 *
	 * @return void
	 */
	public function test_the_readme_has_the_required_sections(): void {
		$readme = $this->file( 'readme.txt' );

		$this->assertStringStartsWith( '=== ' . Brand::fullName() . ' ===', $readme );

		foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Requires PHP', 'Stable tag', 'License' ) as $field ) {
			$this->assertNotSame(
				'',
				$this->readmeField( $field ),
				sprintf( 'readme.txt is missing "%s".', $field )
			);
		}

		foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog' ) as $section ) {
			$this->assertStringContainsString(
				'== ' . $section . ' ==',
				$readme,
				sprintf( 'readme.txt is missing the "%s" section.', $section )
			);
		}

		// Five tags is the limit wordpress.org indexes.
		$tags = array_filter( array_map( 'trim', explode( ',', $this->readmeField( 'Tags' ) ) ) );

		$this->assertLessThanOrEqual( 5, count( $tags ), 'wordpress.org indexes at most five tags.' );

		// The short description is the line under the headers, and it is
		// truncated past 150 characters.
		$matched = preg_match( '/^License URI:.*\R+(.+)$/m', $readme, $lines );

		$this->assertSame( 1, $matched, 'readme.txt has no short description.' );
		$this->assertLessThanOrEqual(
			150,
			strlen( trim( $lines[1] ) ),
			'The short description is truncated past 150 characters.'
		);

		// And the requirements agree with the plugin header, which is the pair
		// most often left to drift.
		$header = $this->file( 'debloater.php' );

		$this->assertSame( $this->headerField( $header, 'Requires at least' ), $this->readmeField( 'Requires at least' ) );
		$this->assertSame( $this->headerField( $header, 'Requires PHP' ), $this->readmeField( 'Requires PHP' ) );
	}

	/**
	 * Every screenshot the readme lists is a screenshot somebody has to take.
	 *
	 * Asserted as a list rather than as files, because the files are release
	 * assets rather than repository content — wordpress.org serves them from
	 * `/assets`, not from the plugin. What this catches is a readme that
	 * promises four screenshots when the release only has three.
	 *
	 * @return void
	 */
	public function test_the_screenshot_list_is_numbered_from_one(): void {
		$readme = $this->file( 'readme.txt' );

		$matched = preg_match( '/== Screenshots ==\R+(.*?)(?=\R== )/s', $readme, $section );

		$this->assertSame( 1, $matched, 'readme.txt has no Screenshots section.' );

		preg_match_all( '/^(\d+)\./m', $section[1], $numbers );

		$this->assertNotEmpty( $numbers[1], 'The Screenshots section lists none.' );
		$this->assertSame(
			range( 1, count( $numbers[1] ) ),
			array_map( 'intval', $numbers[1] ),
			'Screenshots must be numbered from 1 with no gaps: wordpress.org matches them to screenshot-N.png.'
		);
	}

	/**
	 * The GPL is declared everywhere it has to be.
	 *
	 * @return void
	 */
	public function test_the_licence_is_declared_consistently(): void {
		$this->assertFileExists( $this->path( 'LICENSE' ) );
		$this->assertStringContainsString(
			'GNU GENERAL PUBLIC LICENSE',
			$this->file( 'LICENSE' )
		);

		$expected = 'GPL-2.0-or-later';

		$this->assertSame( $expected, $this->readmeField( 'License' ) );
		$this->assertSame( $expected, $this->headerField( $this->file( 'debloater.php' ), 'License' ) );

		$composer = json_decode( $this->file( 'composer.json' ), true );
		$package  = json_decode( $this->file( 'package.json' ), true );

		$this->assertIsArray( $composer );
		$this->assertIsArray( $package );
		$this->assertSame( $expected, $composer['license'] );
		$this->assertSame( $expected, $package['license'] );

		// And the must-use loader, which is a plugin in its own right as far as
		// WordPress is concerned and gets listed as one.
		$this->assertStringContainsString(
			'License: ' . $expected,
			$this->file( 'mu-loader/debloater-loader.php' )
		);
	}

	/**
	 * The identifiers are defined once, in Brand.
	 *
	 * BUILD-SPEC §17 Phase 18: "apply them only through the Brand class and
	 * build config".
	 *
	 * @return void
	 */
	public function test_identifiers_are_defined_only_in_brand(): void {
		foreach ( self::IDENTIFIERS as $identifier ) {
			$offenders = array();

			foreach ( $this->sourceFiles() as $relative => $source ) {
				if ( 'src/Brand.php' === $relative ) {
					continue;
				}

				// A runtime handler has no autoloader and therefore cannot see
				// Brand at all (BUILD-SPEC §10). The capability name in the
				// kill-switch guard is the one place a literal is unavoidable,
				// and a test asserts the two agree instead.
				if ( str_starts_with( $relative, 'runtime-handlers/' ) ) {
					continue;
				}

				if ( str_contains( $this->withoutComments( $source ), "'" . $identifier . "'" ) ) {
					$offenders[] = $relative;
				}
			}

			$this->assertSame(
				array(),
				$offenders,
				sprintf(
					'"%s" is defined in Brand and must be referenced from there, not repeated in: %s',
					$identifier,
					implode( ', ', $offenders )
				)
			);
		}
	}

	/**
	 * The one literal a runtime handler is allowed still matches Brand.
	 *
	 * @return void
	 */
	public function test_the_runtime_guard_capability_matches_brand(): void {
		$guard = $this->file( 'runtime-handlers/runtime-guard.php' );

		$this->assertStringContainsString(
			"const CAPABILITY = '" . Brand::CAPABILITY . "';",
			$guard,
			'The kill-switch guard cannot autoload Brand, so its copy of the capability must be kept in step by this test.'
		);
	}

	/**
	 * The slug is the slug, everywhere.
	 *
	 * @return void
	 */
	public function test_the_slug_agrees_with_the_packaging(): void {
		$this->assertSame( Brand::SLUG, Brand::TEXT_DOMAIN, 'wordpress.org derives the text domain from the slug.' );
		$this->assertSame( Brand::SLUG, Brand::MENU_SLUG );

		$package = json_decode( $this->file( 'package.json' ), true );

		$this->assertIsArray( $package );
		$this->assertSame( Brand::SLUG, $package['name'] );

		// The entry point's filename is what WordPress uses as the plugin's
		// directory-relative identity, and wordpress.org expects
		// `<slug>/<slug>.php`.
		$this->assertFileExists( $this->path( Brand::SLUG . '.php' ) );
	}

	/**
	 * Every translatable string uses this plugin's text domain, and only it.
	 *
	 * @return void
	 */
	public function test_every_translatable_string_uses_our_text_domain(): void {
		$functions = 'esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n_noop|_nx|_ex|_x|__|_e|_n';
		$wrong     = array();

		foreach ( $this->sourceFiles() as $relative => $source ) {
			// Every domain argument in the file, whatever function it belongs
			// to: the domain is always the last string argument, so a wrong one
			// shows up as a string literal that is neither ours nor a
			// placeholder.
			if ( ! preg_match_all( '/\b(' . $functions . ')\(/', $source, $calls, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $calls[0] as $call ) {
				$argument = $this->lastStringArgument( $source, (int) $call[1] + strlen( $call[0] ) );

				if ( null === $argument ) {
					$wrong[] = $relative . ': ' . $call[0] . ' has no text domain';

					continue;
				}

				if ( Brand::TEXT_DOMAIN !== $argument ) {
					$wrong[] = $relative . ': ' . $call[0] . " uses '" . $argument . "'";
				}
			}
		}

		$this->assertSame( array(), $wrong, implode( "\n", $wrong ) );
	}

	/**
	 * The POT file exists, is current, and covers the strings in the source.
	 *
	 * Not regenerated here — that needs WP-CLI, which this suite deliberately
	 * runs without. What it checks is that the committed POT is not stale in
	 * the way that matters: a string added to the source and never extracted is
	 * a string no translator can see.
	 *
	 * @return void
	 */
	public function test_the_pot_file_covers_the_source(): void {
		$pot = $this->file( 'languages/' . Brand::SLUG . '.pot' );

		$this->assertStringContainsString( '"Project-Id-Version:', $pot );
		$this->assertStringContainsString( 'X-Domain: ' . Brand::TEXT_DOMAIN, $pot );

		// A sample rather than all 500: every string in the POT is quoted and
		// escaped in gettext's own way, and reimplementing that unescaping here
		// to compare every one would be reimplementing the extractor. What this
		// needs to catch is a POT generated before the last few strings were
		// written, and for that a handful of the most recently added ones is
		// enough — together with the count, below.
		$strings = array();

		foreach ( $this->sourceFiles() as $relative => $source ) {
			if ( ! str_starts_with( $relative, 'src/' ) ) {
				continue;
			}

			// Single-line, short, no placeholders and no escapes: the strings
			// that appear in the POT byte-for-byte.
			if ( preg_match_all( "/\b__\(\s*'([A-Za-z][A-Za-z0-9 ,.…-]{10,60})'\s*,\s*'" . Brand::TEXT_DOMAIN . "'/", $source, $found ) ) {
				foreach ( $found[1] as $string ) {
					$strings[ $string ] = $relative;
				}
			}
		}

		$this->assertGreaterThan( 20, count( $strings ), 'this check needs a decent sample to be worth anything' );

		$missing = array();

		foreach ( $strings as $string => $relative ) {
			if ( ! str_contains( $pot, 'msgid "' . $string . '"' ) ) {
				$missing[] = $relative . ': ' . $string;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"The POT file is stale — these strings are in the source but not extracted:\n"
				. implode( "\n", $missing )
				. "\n\nRegenerate it with: npm run i18n:pot"
		);
	}

	/**
	 * Uninstall and packaging files exist and are wired up.
	 *
	 * @return void
	 */
	public function test_the_packaging_files_exist(): void {
		$this->assertFileExists( $this->path( 'uninstall.php' ) );
		$this->assertFileExists( $this->path( '.distignore' ) );
		$this->assertFileExists( $this->path( 'tools/build-zip.mjs' ) );

		$package = json_decode( $this->file( 'package.json' ), true );

		$this->assertIsArray( $package );
		$this->assertArrayHasKey( 'plugin-zip', $package['scripts'] );
		$this->assertArrayHasKey( 'i18n:pot', $package['scripts'] );

		// The deny-list has to deny the things whose presence in a zip would
		// matter most, whatever else it says.
		$distignore = $this->file( '.distignore' );

		foreach ( array( 'tests', 'node_modules', '.github', 'admin-ui' ) as $entry ) {
			$this->assertMatchesRegularExpression(
				'/^' . preg_quote( $entry, '/' ) . '$/m',
				$distignore,
				sprintf( '.distignore must exclude %s.', $entry )
			);
		}
	}

	/**
	 * The decision this phase was required to record.
	 *
	 * @return void
	 */
	public function test_the_naming_decision_is_recorded(): void {
		$decisions = $this->file( 'docs/DECISIONS.md' );

		$this->assertStringContainsString(
			'public name',
			strtolower( $decisions ),
			'BUILD-SPEC §16 requires the public name and wp.org slug to be recorded as a decision.'
		);
		$this->assertStringContainsString( Brand::SLUG, $decisions );
	}

	/**
	 * The last string argument of a call starting at an offset.
	 *
	 * Scans forward through the argument list tracking nesting and quoting, so
	 * a comma or a bracket inside a string does not confuse it. Returns null
	 * when the last argument is not a plain single-quoted string, which is what
	 * a missing text domain looks like.
	 *
	 * @param string $source PHP source.
	 * @param int    $from   Offset just after the opening bracket.
	 * @return string|null
	 */
	private function lastStringArgument( string $source, int $from ): ?string {
		$depth   = 1;
		$length  = strlen( $source );
		$last    = null;
		$current = null;
		$quote   = '';

		for ( $index = $from; $index < $length; $index++ ) {
			$character = $source[ $index ];

			if ( '' !== $quote ) {
				if ( '\\' === $character ) {
					if ( null !== $current ) {
						$current .= $character . ( $source[ $index + 1 ] ?? '' );
					}

					++$index;

					continue;
				}

				if ( $character === $quote ) {
					$quote = '';

					continue;
				}

				if ( null !== $current ) {
					$current .= $character;
				}

				continue;
			}

			if ( "'" === $character || '"' === $character ) {
				$quote = $character;

				if ( 1 === $depth ) {
					$current = "'" === $character ? '' : null;
				}

				continue;
			}

			if ( '(' === $character || '[' === $character ) {
				++$depth;

				continue;
			}

			if ( ')' === $character || ']' === $character ) {
				--$depth;

				if ( 0 === $depth ) {
					return null === $current ? $last : $current;
				}

				continue;
			}

			if ( ',' === $character && 1 === $depth ) {
				$last    = $current;
				$current = null;

				continue;
			}

			// Anything else at argument level means this argument is not a
			// plain string literal — a concatenation, a constant, a variable.
			if ( 1 === $depth && '' === trim( $character ) ) {
				continue;
			}

			if ( 1 === $depth && null !== $current && '' !== $current ) {
				$current = null;
			}
		}

		return $last;
	}

	/**
	 * Source with its comments removed.
	 *
	 * Because a docblock that quotes an identifier is documentation, not a
	 * second definition of it — and telling somebody reading
	 * `Capabilities::map()` which capability it maps is exactly what a docblock
	 * is for.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function withoutComments( string $source ): string {
		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * Every PHP file that ships, keyed by path relative to the root.
	 *
	 * @return array<string,string>
	 */
	private function sourceFiles(): array {
		$files = array();
		$root  = str_replace( '\\', '/', $this->path( '' ) );

		foreach ( array( 'src', 'runtime-handlers', 'mu-loader' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->path( $directory ), \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) );

				$files[ $relative ] = (string) file_get_contents( $file->getPathname() );
			}
		}

		foreach ( array( 'uninstall.php', 'debloater.php' ) as $relative ) {
			$files[ $relative ] = $this->file( $relative );
		}

		return $files;
	}

	/**
	 * One value out of a plugin header.
	 *
	 * @param string $header Plugin file contents.
	 * @param string $field  Header field name.
	 * @return string
	 */
	private function headerField( string $header, string $field ): string {
		$matched = preg_match(
			'/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m',
			$header,
			$value
		);

		return 1 === $matched ? $value[1] : '';
	}

	/**
	 * One value out of readme.txt's header block.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function readmeField( string $field ): string {
		$matched = preg_match(
			'/^' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m',
			$this->file( 'readme.txt' ),
			$value
		);

		return 1 === $matched ? $value[1] : '';
	}

	/**
	 * An absolute path inside the repository.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	/**
	 * Read a repository file.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function file( string $relative ): string {
		$path = $this->path( $relative );

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}
}
