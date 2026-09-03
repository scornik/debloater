<?php
/**
 * Level B rows too large to keep in the database.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Snapshot;

use RuntimeException;
use Debloater\Contracts\Context;
use Debloater\Contracts\Json;
use Debloater\Contracts\SnapshotItem;

/**
 * Gzipped newline-delimited JSON under wp-content/debloater/backups (§4, §8).
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
	 * Permissions for the backups directory.
	 */
	private const DIR_MODE = 0755;

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
		return $this->context->backupsDir() . '/snapshot-' . $snapshot_id . '.ndjson.gz';
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
		$directory = $this->context->backupsDir();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WP_Filesystem may need credentials we do not have during an apply; the directory is inside wp-content/debloater and nowhere else.
		if ( ! is_dir( $directory ) && ! mkdir( $directory, self::DIR_MODE, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( sprintf( 'Could not create the backups directory: %s', $directory ) );
		}

		if ( ! is_writable( $directory ) ) {
			throw new RuntimeException( sprintf( 'The backups directory is not writable: %s', $directory ) );
		}

		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);

		foreach ( $guards as $name => $contents ) {
			$path = $directory . '/' . $name;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a static guard file into our own directory.
				file_put_contents( $path, $contents );
			}
		}

		return $directory;
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
		$directory = realpath( $this->context->backupsDir() );
		$resolved  = realpath( $path );

		if ( false === $resolved ) {
			// The file does not exist; check the directory it would sit in.
			$resolved = realpath( dirname( $path ) );
			$resolved = false === $resolved ? '' : $resolved . '/' . basename( $path );
		}

		if ( false === $directory || '' === $resolved || ! str_starts_with( $resolved, $directory ) ) {
			throw new RuntimeException(
				sprintf( 'Refusing to touch a recovery file outside the backups directory: %s', $path )
			);
		}
	}
}
