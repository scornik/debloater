/**
 * The bundle stays small, or the build fails.
 *
 * BUILD-SPEC §17 Phase 8 sets the budget at 250 KB gzipped. A plugin that
 * removes weight from other people's sites and ships half a megabyte of its own
 * JavaScript to do it has an argument it cannot win.
 *
 * Measured gzipped because that is what a browser actually downloads.
 */

import { gzipSync } from 'node:zlib';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const BUDGET_BYTES = 250 * 1024;
const BUILD_DIR = fileURLToPath( new URL( '../build/', import.meta.url ) );

const files = ( () => {
	try {
		return readdirSync( BUILD_DIR ).filter( ( name ) => /\.(js|css)$/.test( name ) );
	} catch {
		return [];
	}
} )();

if ( files.length === 0 ) {
	console.error( 'No built assets found. Run `npm run build` first.' );
	process.exit( 1 );
}

let total = 0;

for ( const name of files ) {
	const path = join( BUILD_DIR, name );
	const raw = statSync( path ).size;
	const gz = gzipSync( readFileSync( path ) ).length;

	// The RTL stylesheet is never loaded alongside the LTR one; counting both
	// would budget for a page that cannot exist.
	if ( name.endsWith( '-rtl.css' ) ) {
		console.log( `  ${ name.padEnd( 28 ) } ${ String( gz ).padStart( 7 ) } B gz (not counted)` );
		continue;
	}

	total += gz;

	console.log( `  ${ name.padEnd( 28 ) } ${ String( gz ).padStart( 7 ) } B gz  (${ raw } B raw)` );
}

const percent = Math.round( ( total / BUDGET_BYTES ) * 100 );

console.log( `\n  Total ${ total } B gzipped — ${ percent }% of the ${ BUDGET_BYTES } B budget.` );

if ( total > BUDGET_BYTES ) {
	console.error( '\nThe admin bundle is over budget.' );
	process.exit( 1 );
}

console.log( 'Within budget.' );
