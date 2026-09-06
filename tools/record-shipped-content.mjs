#!/usr/bin/env node
/**
 * Re-record what the free plugin ships, for `tests/Packaging/zip.test.mjs`.
 *
 * `free-plugin-content.json` is the list of every file in the release archive:
 * source files by content hash, generated files by path. The test that reads it
 * fails when a release adds, removes or alters a shipped file, and names the
 * file — which is only useful if regenerating it is a deliberate act with a
 * sentence attached, rather than the reflex fix for a red build.
 *
 * So this refuses to run without `--why`, and writes that reason into the file.
 * Somebody reading the diff in a year gets the answer with it.
 *
 *     node tools/record-shipped-content.mjs --why "Phase 20 added the X route"
 *
 * It reads `dist/debloater-<version>.zip`, so build first — `composer
 * check:packaging` does both in the right order.
 */

import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const TARGET = path.join( ROOT, 'tests', 'Packaging', 'free-plugin-content.json' );

const VERSION = JSON.parse( fs.readFileSync( path.join( ROOT, 'package.json' ), 'utf8' ) ).version;
const ARCHIVE = path.join( ROOT, 'dist', `debloater-${ VERSION }.zip` );

/**
 * The files whose bytes a second machine cannot reproduce.
 *
 * Kept identical to `GENERATED` in tests/Packaging/zip.test.mjs, which asserts
 * the list rather than trusting it. See that file for why each one is here.
 *
 * @param {string} name Entry name.
 * @return {boolean} Whether it is recorded by path rather than by content.
 */
const isGenerated = ( name ) =>
	[
		'debloater/build/index.js',
		'debloater/build/index.asset.php',
		'debloater/vendor/autoload.php',
		'debloater/vendor/composer/autoload_real.php',
		'debloater/vendor/composer/autoload_static.php',
	].includes( name );

const why = ( () => {
	const at = process.argv.indexOf( '--why' );

	return at === -1 ? '' : ( process.argv[ at + 1 ] || '' ).trim();
} )();

if ( why === '' ) {
	process.stderr.write(
		'\nRefusing to re-record without a reason.\n\n' +
			'This file is the record of what a release ships. Regenerating it to make a\n' +
			'red build green is how the record stops meaning anything, so the reason goes\n' +
			'in the file:\n\n' +
			'    node tools/record-shipped-content.mjs --why "what changed, and why"\n\n'
	);
	process.exit( 1 );
}

if ( ! fs.existsSync( ARCHIVE ) ) {
	process.stderr.write(
		`\nThere is no ${ path.relative( ROOT, ARCHIVE ) } to read.\n\n` +
			'Build it first, with a production autoloader:\n\n' +
			'    composer check:packaging\n\n'
	);
	process.exit( 1 );
}

/**
 * Every file in the archive, in the archive's own order.
 *
 * @return {string[]} Entry names.
 */
const entryNames = () =>
	execFileSync( 'unzip', [ '-Z1', ARCHIVE ], { encoding: 'utf8', maxBuffer: 1 << 28 } )
		.split( '\n' )
		.filter( ( name ) => name !== '' && ! name.endsWith( '/' ) );

const previous = fs.existsSync( TARGET )
	? JSON.parse( fs.readFileSync( TARGET, 'utf8' ) )
	: { entries: {}, generated: [], history: [] };

const entries = {};
const generated = [];

for ( const name of entryNames() ) {
	if ( isGenerated( name ) ) {
		generated.push( name );

		continue;
	}

	const bytes = execFileSync( 'unzip', [ '-p', ARCHIVE, name ], { maxBuffer: 1 << 28 } );

	entries[ name ] = crypto.createHash( 'sha256' ).update( bytes ).digest( 'hex' );
}

const added = Object.keys( entries ).filter( ( n ) => ! ( n in ( previous.entries || {} ) ) );
const gone = Object.keys( previous.entries || {} ).filter( ( n ) => ! ( n in entries ) );
const changed = Object.keys( entries ).filter(
	( n ) => n in ( previous.entries || {} ) && entries[ n ] !== previous.entries[ n ]
);

const document = {
	_comment: [
		'What the free plugin ships, by content hash -- except for the five',
		'files listed under "generated", whose bytes a second machine cannot',
		'reproduce (webpack output, and the Composer autoloader files that embed',
		'the install path). Those are recorded by path.',
		'',
		'tests/Packaging/zip.test.mjs has the reasoning, and asserts that the',
		'list of five has not grown.',
		'',
		'First recorded from dist/debloater-0.1.1.zip at commit 699eace, the',
		'artifact that was about to go to wordpress.org, so that splitting Pro',
		'out of this repository could be proved not to have changed the plugin.',
		'',
		'Regenerate with tools/record-shipped-content.mjs --why "...". It will',
		'not run without a reason, and the reason lands in "history" below.',
	],
	version: VERSION,
	recorded_at_commit: previous.recorded_at_commit || '699eace',
	entry_count: Object.keys( entries ).length + generated.length,
	hashed_count: Object.keys( entries ).length,
	generated_count: generated.length,
	history: [
		...( previous.history || [] ),
		{
			why,
			added,
			removed: gone,
			changed,
		},
	],
	generated,
	entries,
};

fs.writeFileSync( TARGET, `${ JSON.stringify( document, null, 2 ) }\n` );

process.stdout.write(
	`Recorded ${ document.entry_count } files ` +
		`(${ document.hashed_count } hashed, ${ document.generated_count } generated).\n` +
		`  added:   ${ added.length }\n` +
		`  removed: ${ gone.length }\n` +
		`  changed: ${ changed.length }\n`
);
