<?php
/**
 * Generate — and optionally sign — the registry manifest.
 *
 * A release of the registry is a tag plus a manifest: every file in it, with its
 * SHA-256, signed once over the canonical form of the whole list. This writes
 * the manifest; signing is a separate step that needs the private key, which
 * lives on the machine that cuts a release and nowhere else.
 *
 * Usage:
 *
 *     php tools/registry-manifest.php --tag=v1.2.0
 *     php tools/registry-manifest.php --tag=v1.2.0 --check
 *     php tools/registry-manifest.php --tag=v1.2.0 --sign=/secure/path/to/ed25519.key
 *
 * `--check` verifies the manifest on disk against the files on disk and changes
 * nothing, which is what CI runs.
 *
 * `--sign` reads a hex-encoded Ed25519 secret key from a file **outside this
 * repository** and writes `registry/manifest.json.sig`. It refuses a key path
 * inside the working tree, because a signing key that gets committed once is
 * compromised for ever.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This is a command-line tool.\n" );
	exit( 1 );
}

$root     = dirname( __DIR__ );
$registry = $root . '/registry';

$options = getopt( '', array( 'tag:', 'sign:', 'check' ) );
$tag     = isset( $options['tag'] ) ? (string) $options['tag'] : '';
$check   = array_key_exists( 'check', $options );

/**
 * Every JSON file in the registry, relative to the registry directory.
 *
 * The manifest itself and its signature are excluded: a list cannot contain its
 * own hash, and a signature over a document that includes the signature is not
 * a thing.
 *
 * @param string $registry Absolute path of the registry directory.
 * @return array<int,string>
 */
function debloater_registry_files( string $registry ): array {
	$found    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $registry, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || 'json' !== $file->getExtension() ) {
			continue;
		}

		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $registry ) + 1 ) );

		if ( 'manifest.json' === $relative ) {
			continue;
		}

		$found[] = $relative;
	}

	sort( $found, SORT_STRING );

	return $found;
}

$files  = debloater_registry_files( $registry );
$hashes = array();

foreach ( $files as $relative ) {
	$contents = file_get_contents( $registry . '/' . $relative );

	if ( false === $contents ) {
		fwrite( STDERR, sprintf( "Could not read %s\n", $relative ) );
		exit( 1 );
	}

	if ( null === json_decode( $contents, true ) ) {
		fwrite( STDERR, sprintf( "%s is not valid JSON\n", $relative ) );
		exit( 1 );
	}

	$hashes[ $relative ] = hash( 'sha256', $contents );
}

$manifest_path = $registry . '/manifest.json';

if ( $check ) {
	$existing = file_get_contents( $manifest_path );

	if ( false === $existing ) {
		fwrite( STDERR, "There is no registry/manifest.json to check.\n" );
		exit( 1 );
	}

	$decoded = json_decode( $existing, true );
	$listed  = is_array( $decoded ) && isset( $decoded['files'] ) && is_array( $decoded['files'] )
		? $decoded['files']
		: array();

	$missing = array_diff_key( $hashes, $listed );
	$extra   = array_diff_key( $listed, $hashes );
	$changed = array();

	foreach ( $hashes as $relative => $hash ) {
		if ( isset( $listed[ $relative ] ) && $listed[ $relative ] !== $hash ) {
			$changed[] = $relative;
		}
	}

	if ( array() !== $missing || array() !== $extra || array() !== $changed ) {
		fwrite( STDERR, "The manifest does not describe the registry on disk.\n" );

		foreach ( array_keys( $missing ) as $relative ) {
			fwrite( STDERR, sprintf( "  not in the manifest: %s\n", $relative ) );
		}

		foreach ( array_keys( $extra ) as $relative ) {
			fwrite( STDERR, sprintf( "  in the manifest but not on disk: %s\n", $relative ) );
		}

		foreach ( $changed as $relative ) {
			fwrite( STDERR, sprintf( "  changed since the manifest: %s\n", $relative ) );
		}

		fwrite( STDERR, "\nRun this tool with --tag=<tag> to regenerate it.\n" );
		exit( 1 );
	}

	printf( "The manifest matches all %d registry files.\n", count( $hashes ) );
	exit( 0 );
}

if ( '' === $tag ) {
	fwrite( STDERR, "Pass --tag=<tag>, or --check to verify the manifest on disk.\n" );
	exit( 1 );
}

$manifest = array(
	'schema_version' => 1,
	'product'        => 'debloater',
	'tag'            => $tag,
	'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
	'files'          => $hashes,
);

file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
);

printf( "Wrote registry/manifest.json for %s with %d files.\n", $tag, count( $hashes ) );

if ( ! isset( $options['sign'] ) ) {
	printf( "Not signed. Pass --sign=<path to the private key, outside this repository>.\n" );
	exit( 0 );
}

$key_path = (string) $options['sign'];
$real_key = realpath( $key_path );
$real_root = realpath( $root );

if ( false === $real_key ) {
	fwrite( STDERR, sprintf( "No key at %s\n", $key_path ) );
	exit( 1 );
}

if ( false !== $real_root && str_starts_with( str_replace( '\\', '/', $real_key ), str_replace( '\\', '/', $real_root ) ) ) {
	// A signing key inside the working tree gets committed eventually, and a
	// committed signing key is public for ever.
	fwrite( STDERR, "Refusing to read a signing key from inside the repository.\n" );
	exit( 1 );
}

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	fwrite( STDERR, "This PHP has no libsodium, so it cannot sign.\n" );
	exit( 1 );
}

/**
 * The canonical form of a value: object keys sorted, everywhere.
 *
 * The same rule `Debloater\Contracts\Json::canonical()` applies, restated here
 * because this tool runs without the plugin's autoloader.
 *
 * @param mixed $value Value to canonicalise.
 * @return mixed
 */
function debloater_canonical( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$sorted = array();

	foreach ( $value as $key => $item ) {
		$sorted[ $key ] = debloater_canonical( $item );
	}

	if ( ! array_is_list( $sorted ) ) {
		ksort( $sorted, SORT_STRING );
	}

	return $sorted;
}

$secret_hex = trim( (string) file_get_contents( $real_key ) );
$secret     = hex2bin( $secret_hex );

if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
	fwrite( STDERR, "That file is not a hex-encoded Ed25519 secret key.\n" );
	exit( 1 );
}

$canonical = json_encode(
	debloater_canonical( $manifest ),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$signature = sodium_crypto_sign_detached( (string) $canonical, $secret );

file_put_contents( $registry . '/manifest.json.sig', bin2hex( $signature ) . "\n" );

sodium_memzero( $secret );

printf(
	"Signed. The public key to pin is %s\n",
	bin2hex( sodium_crypto_sign_publickey_from_secretkey( hex2bin( $secret_hex ) ?: '' ) )
);
