/**
 * Fetch the PHPUnit 9.6 phar the integration suite runs on.
 *
 * The WordPress core test suite is not compatible with PHPUnit 10 —
 * WP_UnitTestCase calls PHPUnit\Util\Test::parseTestMethodAnnotations(), which
 * PHPUnit 10 removed — so the integration suite runs on 9.6 while the unit
 * suite stays on the 10.5 required by BUILD-SPEC §3. See docs/DECISIONS.md
 * D-0008.
 *
 * The phar cannot be a Composer dependency: it would have to replace PHPUnit 10
 * in the same dependency tree. It is downloaded here instead, verified against
 * its published SHA-256, and gitignored.
 */

import { createWriteStream } from 'node:fs';
import { mkdir, readFile, rm, stat } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { get } from 'node:https';

const VERSION = '9.6.22';
const SHA256 = '9618d52015c9b06b4979a8e481ca9567be6be20e711e98926c61378a400e1f2e';
const TOOLS = dirname( fileURLToPath( import.meta.url ) );
const TARGET = join( TOOLS, 'phpunit-9.phar' );

/**
 * Follow redirects and download to a file.
 *
 * @param {string} url         URL to fetch.
 * @param {string} destination Destination path.
 * @return {Promise<void>}
 */
function download( url, destination ) {
	return new Promise( ( resolve, reject ) => {
		get( url, ( response ) => {
			if ( response.statusCode >= 300 && response.statusCode < 400 && response.headers.location ) {
				response.resume();
				download( response.headers.location, destination ).then( resolve, reject );
				return;
			}

			if ( response.statusCode !== 200 ) {
				response.resume();
				reject( new Error( `Unexpected status ${ response.statusCode } for ${ url }` ) );
				return;
			}

			const file = createWriteStream( destination );

			response.pipe( file );
			file.on( 'finish', () => file.close( () => resolve() ) );
			file.on( 'error', reject );
		} ).on( 'error', reject );
	} );
}

/**
 * The SHA-256 of a file.
 *
 * @param {string} path File path.
 * @return {Promise<string>}
 */
async function digest( path ) {
	return createHash( 'sha256' ).update( await readFile( path ) ).digest( 'hex' );
}

const existing = await stat( TARGET ).catch( () => null );

if ( existing ) {
	console.log( `PHPUnit ${ VERSION } phar already present.` );
	process.exit( 0 );
}

await mkdir( TOOLS, { recursive: true } );

console.log( `Downloading PHPUnit ${ VERSION }…` );

await download( `https://phar.phpunit.de/phpunit-${ VERSION }.phar`, TARGET );

const actual = await digest( TARGET );

// The published checksum is recorded above. If it has not been filled in for a
// new version, report the actual digest rather than pretending to have verified
// something: a checksum nobody checks is worse than an honest gap.
if ( SHA256 && actual !== SHA256 ) {
	await rm( TARGET, { force: true } );

	throw new Error(
		`Checksum mismatch for phpunit-${ VERSION }.phar.\n` +
			`  expected ${ SHA256 }\n  actual   ${ actual }\n` +
			'The download was discarded. Verify the release before updating the expected value.'
	);
}

console.log( `PHPUnit ${ VERSION } phar ready (sha256 ${ actual }).` );
