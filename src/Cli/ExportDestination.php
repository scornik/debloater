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
 * `profile export` and `export` once wrote wherever `--file` pointed, and
 * to print to standard output otherwise. wordpress.org's review asked for a
 * default destination the plugin owns, so that the ordinary case does not
 * require the operator to pick a path and does not put a plugin's output
 * wherever the shell happened to be.
 *
 * `wp_upload_dir()` is that place: it is the directory WordPress already
 * guarantees is writable, it moves with the site when `UPLOADS` or a filter
 * says so, and it is where a site's own generated files belong.
 *
 * ## There is no way to write anywhere else
 *
 * 0.3.0 kept `--file=<path>` for WP-CLI, reasoning that somebody at a shell can
 * already write wherever the web user can. wordpress.org round 2 refused that,
 * and the refusal is better than the reasoning: a rule with no exceptions
 * survives contact with the next person to add an export, and a rule with one
 * good exception does not (`D-0074`).
 *
 * `--file=-` still prints to standard output, because a pipe is not a file
 * write and losing it would mean everybody wrapping the command in a shell
 * script that redirects.
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
	 * @param string $basename A name to build the file from.
	 * @return string Absolute path to write to.
	 * @throws RuntimeException When the directory cannot be prepared.
	 */
	public function resolve( string $basename ): string {
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
