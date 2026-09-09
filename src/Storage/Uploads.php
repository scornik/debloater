<?php
/**
 * The one place under uploads this plugin writes.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Storage;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable
// -- This is `wp_upload_dir()`, the directory WordPress guarantees is writable
// and the one VIP documents for file operations. Asking before writing lets the
// caller say what is wrong; WP_Filesystem would ask for FTP credentials in the
// middle of an apply, which is worse than the check it would replace.
//
// Annotated here rather than in phpcs.xml.dist on purpose: a suppression in the
// config is invisible to Plugin Check, so it is unsuppressed for the reviewer
// and for wordpress.org (D-0004, P9).

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw.
// Rest\Controller::guard() escapes every Throwable at the REST edge and Cli\Command catches at the CLI edge,
// which is where BUILD-SPEC §13 rule 4 puts escaping; tests/Integration/ExceptionBoundaryTest.php holds both.

use RuntimeException;

/**
 * `wp-content/uploads/debloater/`, created on demand and closed to the web.
 *
 * ## Why uploads and not wp-content
 *
 * Until 0.3.0 this plugin wrote to `wp-content/debloater/` — first a generated
 * runtime, later only recovery spills. wordpress.org's review objected to the
 * generated PHP (`D-0070`), and once that was gone the directory was still
 * there holding data, still prompting the question "why does this plugin write
 * outside uploads at all".
 *
 * There is no good answer, so it does not any more. `wp_upload_dir()` is the
 * directory WordPress guarantees is writable, moves with `UPLOADS` and filters,
 * and is where a site's own generated files are expected to be.
 *
 * ## Closed to the web
 *
 * An `index.php` and a `.htaccess` go in on creation. Neither does anything on
 * nginx, which is why the callers name their files unguessably as well — a
 * recovery point is a copy of rows from somebody's database and a spill file
 * sitting at a URL somebody can try is the whole problem.
 */
final class Uploads {

	/**
	 * The folder created inside the uploads directory.
	 */
	public const FOLDER = 'debloater';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * A directory under `uploads/debloater`, created and closed to the web.
	 *
	 * @param string $sub Optional subdirectory, such as `backups`.
	 * @return string Absolute path, with no trailing slash.
	 * @throws RuntimeException When it cannot be created or written to.
	 */
	public static function directory( string $sub = '' ): string {
		$directory = self::base();

		if ( '' !== $sub ) {
			$directory .= '/' . trim( $sub, '/' );
		}

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( sprintf( 'Could not create %s', $directory ) );
		}

		if ( ! is_writable( $directory ) ) {
			throw new RuntimeException( sprintf( '%s is not writable', $directory ) );
		}

		self::guard( $directory );

		return $directory;
	}

	/**
	 * `uploads/debloater`, without creating anything.
	 *
	 * @return string
	 * @throws RuntimeException When the uploads directory is unusable.
	 */
	public static function base(): string {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || ! isset( $uploads['basedir'] ) ) {
			throw new RuntimeException(
				sprintf(
					'The uploads directory is not usable: %s',
					is_string( $uploads['error'] ?? null ) ? $uploads['error'] : 'unknown error'
				)
			);
		}

		return rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) . '/' . self::FOLDER;
	}

	/**
	 * Put the guards in place, if they are not already there.
	 *
	 * @param string $directory Directory to close.
	 * @return void
	 */
	private static function guard( string $directory ): void {
		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);

		foreach ( $guards as $name => $contents ) {
			$path = $directory . '/' . $name;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- A static guard file in our own directory; WP_Filesystem would ask for credentials nobody can answer mid-apply.
				file_put_contents( $path, $contents );
			}
		}
	}
}
