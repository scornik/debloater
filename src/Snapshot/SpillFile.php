<?php
/**
 * Level B rows too large to keep in the database.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Snapshot;

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// -- WP_Filesystem is the wrong tool here, and using it would be less safe rather
// than more.
//
// It cannot do an atomic replace: there is no move() that guarantees rename(2)
// semantics, and a non-atomic write to a file that is loaded on every request is
// exactly how a site ends up serving half a runtime. It also asks for FTP
// credentials when it cannot write directly, which during an apply means a
// credentials prompt in the middle of a change that is already underway.
//
// Everything written here goes inside uploads/debloater/backups, along paths
// this plugin builds itself (BUILD-SPEC §13 rule 6), and
// tests/Integration/SecurityRulesTest.php asserts that boundary.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;
use Debloater\Contracts\Context;
use Debloater\Contracts\Json;
use Debloater\Contracts\SnapshotItem;
use Debloater\Storage\Uploads;

/**
 * Gzipped newline-delimited JSON under uploads/debloater/backups (§4, §8, D-0072).
 *
 * A recovery point for a large deletion can be tens of megabytes. Putting that
 * in `wp_options`-adjacent tables as one row per item works, but a single
 * snapshot of 200 000 rows makes every query against the table slower for
 * everyone, and the rows are read exactly once, in bulk, if they are ever read
 * at all. Above the threshold they go to a file instead.
 *
 * The format is deliberately dull: one canonical JSON object per line, gzipped.
 * It streams in both directions, so neither writing nor reading a large snapshot
 * needs the whole thing in memory, and a truncated file is detectable — the last
 * line will not parse, and the checksum will not match either way.
 *
 * Access to the directory is denied by an index file and, where Apache reads
 * them, an .htaccess. These files contain site data, and a backup that can be
 * fetched over HTTP is a data breach with extra steps.
 */
final class SpillFile {

	/**
	 * Permissions for written files.
	 */
	private const FILE_MODE = 0600;

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Constructor.
	 *
	 * @param Context $context Site context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * The path a snapshot's spill file would take.
	 *
	 * Named from the snapshot id, so a file can never be claimed by two
	 * snapshots and an orphan is traceable back to the row that made it.
	 *
	 * @param int $snapshot_id Snapshot id.
	 * @return string
	 */
	public function pathFor( int $snapshot_id ): string {
		return Uploads::base() . '/backups/snapshot-' . $snapshot_id . '.ndjson.gz';
	}

	/**
	 * Open a spill file for writing.
	 *
	 * @param int $snapshot_id Snapshot id.
	 * @return resource
	 * @throws RuntimeException When the file cannot be opened.
	 */
	public function open( int $snapshot_id ) {
		$this->prepareDirectory();

		$path = $this->pathFor( $snapshot_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_gzopen -- WP_Filesystem has no streaming or gzip interface; a snapshot must not be held in memory to be written.
		$handle = gzopen( $path, 'wb9' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open the recovery file for writing: %s', $path ) );
		}

		return $handle;
	}

	/**
	 * Append one item.
	 *
	 * @param resource     $handle Open spill file.
	 * @param SnapshotItem $item   Item to write.
	 * @return void
	 * @throws RuntimeException When the write fails.
	 */
	public function append( $handle, SnapshotItem $item ): void {
		$line = Json::canonical( $item->toArray() ) . "\n";

		if ( gzwrite( $handle, $line ) !== strlen( $line ) ) {
			throw new RuntimeException( 'A row could not be written to the recovery file.' );
		}
	}

	/**
	 * Close a spill file.
	 *
	 * @param resource $handle      Open spill file.
	 * @param int      $snapshot_id Snapshot id.
	 * @return int Size of the written file in bytes.
	 * @throws RuntimeException When the file cannot be closed or is missing.
	 */
	public function close( $handle, int $snapshot_id ): int {
		if ( ! gzclose( $handle ) ) {
			throw new RuntimeException( 'The recovery file could not be closed cleanly.' );
		}

		$path = $this->pathFor( $snapshot_id );

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( sprintf( 'The recovery file was not written: %s', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricting the file we just created; WP_Filesystem cannot express this without a connection.
		@chmod( $path, self::FILE_MODE );

		clearstatcache( true, $path );

		return (int) filesize( $path );
	}

	/**
	 * Read the items back, one at a time.
	 *
	 * @param string $path Spill file path.
	 * @return iterable<int,SnapshotItem>
	 * @throws RuntimeException When the file is missing or unreadable.
	 */
	public function read( string $path ): iterable {
		$this->assertInsideBackupsDir( $path );

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException(
				sprintf( 'The recovery file for this snapshot is missing: %s', basename( $path ) )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_gzopen -- Streaming read; see open().
		$handle = gzopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not read the recovery file: %s', basename( $path ) ) );
		}

		try {
			$line_number = 0;

			while ( ! gzeof( $handle ) ) {
				// Silenced deliberately: a truncated or damaged archive is an
				// expected input here, not an exceptional one. zlib reports it by
				// returning false, which is handled below; the PHP warning that
				// accompanies it would say the same thing less usefully and would
				// escape as an error in contexts that promote warnings.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
				$line = @gzgets( $handle );

				if ( false === $line ) {
					// End of what can be read. Fewer rows than the snapshot claims
					// is caught by the count and checksum checks in verify().
					break;
				}

				if ( '' === trim( $line ) ) {
					continue;
				}

				++$line_number;

				$decoded = json_decode( $line, true );

				if ( ! is_array( $decoded ) ) {
					throw new RuntimeException(
						sprintf( 'Line %d of the recovery file could not be read.', $line_number )
					);
				}

				yield SnapshotItem::fromArray( $decoded );
			}
		} finally {
			gzclose( $handle );
		}
	}

	/**
	 * Delete a spill file.
	 *
	 * @param string $path Spill file path.
	 * @return bool Whether a file was removed.
	 */
	public function delete( string $path ): bool {
		$this->assertInsideBackupsDir( $path );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Removing a file this class created, inside a directory it owns.
		return unlink( $path );
	}

	/**
	 * Create the backups directory and close it to the web.
	 *
	 * @return string The directory path.
	 * @throws RuntimeException When it cannot be created or written to.
	 */
	private function prepareDirectory(): string {
		return Uploads::directory( 'backups' );
	}

	/**
	 * The old location, kept readable.
	 *
	 * Spills written before 0.3.0 are in `wp-content/debloater/backups`, and
	 * their absolute paths are recorded in the snapshot rows. Moving the
	 * directory without this would orphan every recovery point a site had
	 * open at the moment it upgraded — which is the one file this plugin
	 * cannot afford to lose track of.
	 *
	 * Nothing is written here. It exists so `read()` and `delete()` can still
	 * reach what an older version left.
	 *
	 * @return string
	 */
	private function legacyDirectory(): string {
		return $this->context->legacyDataDir() . '/backups';
	}

	/**
	 * Refuse a path outside the backups directory.
	 *
	 * Resolved first, so a path containing .. cannot walk out of the directory
	 * and have the check pass on the string it was given.
	 *
	 * @param string $path Path to check.
	 * @return void
	 * @throws RuntimeException When the path is outside the backups directory.
	 */
	private function assertInsideBackupsDir( string $path ): void {
		$resolved = realpath( $path );

		if ( false === $resolved ) {
			// The file does not exist; check the directory it would sit in.
			$resolved = realpath( dirname( $path ) );
			$resolved = false === $resolved ? '' : $resolved . '/' . basename( $path );
		}

		if ( '' === $resolved ) {
			throw new RuntimeException(
				sprintf( 'Refusing to touch a recovery file outside the backups directory: %s', $path )
			);
		}

		$resolved = str_replace( '\\', '/', $resolved );

		// Two directories, not one: the current location under uploads, and the
		// pre-0.3.0 one under wp-content, whose absolute paths are recorded in
		// snapshot rows that are still restorable.
		foreach ( array( Uploads::base() . '/backups', $this->legacyDirectory() ) as $candidate ) {
			$directory = realpath( $candidate );

			if ( false !== $directory && str_starts_with( $resolved, str_replace( '\\', '/', $directory ) ) ) {
				return;
			}
		}

		throw new RuntimeException(
			sprintf( 'Refusing to touch a recovery file outside the backups directory: %s', $path )
		);
	}
}
