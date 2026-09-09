<?php
/**
 * Where `wp debloater` writes a file when nobody named a path.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Cli;

use Debloater\Storage\Uploads;
use RuntimeException;

/**
 * A default export location inside the uploads directory.
 *
 * ## Why there is a default at all
 *
 * `profile export` and `export` used to write only where `--file` pointed, and
 * to print to standard output otherwise. wordpress.org's review asked for a
 * default destination the plugin owns, so that the ordinary case does not
 * require the operator to pick a path and does not put a plugin's output
 * wherever the shell happened to be.
 *
 * `wp_upload_dir()` is that place: it is the directory WordPress already
 * guarantees is writable, it moves with the site when `UPLOADS` or a filter
 * says so, and it is where a site's own generated files belong.
 *
 * ## Why `--file` survives
 *
 * Because the objection was never to a plugin writing a file. It was to a
 * plugin writing one to an arbitrary path chosen by something that is not the
 * operator. `--file` is typed by a person with shell access to the server, at a
 * prompt, on their own machine — somebody who can already write anywhere the
 * web user can. Refusing it would remove the ability to export into a
 * deployment pipeline and protect nothing at all.
 *
 * That is a claim about WP-CLI specifically, and it does not extend to the REST
 * routes or the admin screen, neither of which accepts a path from anybody.
 *
 * ## Closed to the web
 *
 * The directory is created on demand with an `index.php` and a `.htaccess`,
 * matching `Snapshot\SpillFile`. A profile names the tweaks a site has applied,
 * which is not a secret but is nobody's business either, and an exported file
 * sitting under a guessable URL is an invitation.
 *
 * On nginx neither guard does anything, which is true of every plugin that
 * writes under uploads and is why the filenames carry random bytes as well.
 */
final class ExportDestination {

	/**
	 * The folder created inside the uploads directory.
	 *
	 * Kept as an alias of `Storage\Uploads::FOLDER` because the tests and the
	 * readme name it, and one definition is enough.
	 */
	public const FOLDER = Uploads::FOLDER;

	/**
	 * Resolve a destination for an export.
	 *
	 * @param string $requested The `--file` value, or '' for the default.
	 * @param string $basename  A name to build the default file from.
	 * @return string Absolute path to write to.
	 * @throws RuntimeException When the default directory cannot be prepared.
	 */
	public function resolve( string $requested, string $basename ): string {
		if ( '' !== $requested ) {
			return $requested;
		}

		return $this->directory() . '/' . $this->filename( $basename );
	}

	/**
	 * The export directory, created and closed to the web.
	 *
	 * @return string
	 * @throws RuntimeException When it cannot be created or written to.
	 */
	public function directory(): string {
		return Uploads::directory();
	}

	/**
	 * A file name that cannot be guessed and cannot escape the directory.
	 *
	 * `sanitize_file_name()` on a name the operator supplied, then random bytes.
	 * The random half is not decoration: without it, "client-baseline.json" is a
	 * URL somebody can try, and the directory guards do nothing on nginx.
	 *
	 * @param string $basename Name to base the file on.
	 * @return string
	 */
	public function filename( string $basename ): string {
		$name = sanitize_file_name( $basename );
		$name = trim( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $name ) ?? '', '-' );

		if ( '' === $name ) {
			$name = 'export';
		}

		return sprintf(
			'%s-%s-%s.json',
			strtolower( $name ),
			gmdate( 'Ymd-His' ),
			bin2hex( random_bytes( 4 ) )
		);
	}
}
