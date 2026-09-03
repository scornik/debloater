#!/usr/bin/env node
/**
 * Build the distributable zip.
 *
 * BUILD-SPEC §17 Phase 18. Staged rather than zipped in place, because what
 * ships and what the repository contains are two different lists and the only
 * way to be sure of the first is to build it explicitly.
 *
 * The staging list is an allow-list, not a deny-list. A `.distignore` is a
 * deny-list, and the failure mode of a deny-list is that anything new ships by
 * default — a scratch file, a key somebody left in the working tree, next
 * phase's half-finished directory. `.distignore` exists too (WordPress tooling
 * reads it), but this is what actually decides.
 *
 * Two things are checked rather than assumed:
 *
 *   1. The admin UI is built. A zip with no `build/` is a plugin whose screen
 *      is blank, and that is not something you want to discover after upload.
 *   2. The Composer autoloader was generated with `--no-dev`. Debloater has
 *      zero production dependencies, so the whole of `vendor/` is one generated
 *      autoloader — but if it was generated with dev dependencies installed it
 *      maps test namespaces and points at PHPUnit. That is both dead weight and
 *      a description of files that are not in the zip.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import url from 'node:url';

const ROOT = path.resolve( path.dirname( url.fileURLToPath( import.meta.url ) ), '..' );
const SLUG = 'debloater';
const DIST = path.join( ROOT, 'dist' );
const STAGE = path.join( DIST, SLUG );

/**
 * Everything that ships, and nothing else.
 *
 * Directories are copied whole except where a filter says otherwise.
 */
const SHIP = [
	{ from: 'debloater.php' },
	{ from: 'uninstall.php' },
	{ from: 'readme.txt' },
	{ from: 'composer.json' },
	{ from: 'LICENSE' },
	{ from: 'src' },
	{ from: 'runtime-handlers' },
	{ from: 'mu-loader' },
	{ from: 'registry' },
	{ from: 'schemas' },
	{ from: 'languages' },

	// The built admin UI, without the source maps: they are a development
	// convenience and several times the size of the code they describe.
	{ from: 'build', filter: ( relative ) => ! relative.endsWith( '.map' ) },

	// The generated autoloader, and only that.
	//
	// Not the whole of `vendor/composer/`: `installed.php`, `installed.json`
	// and `InstalledVersions.php` are Composer's runtime package inventory,
	// nothing in this plugin reads them, and shipping them would put a list of
	// forty dev dependencies into a zip that contains none of them.
	{ from: 'vendor/autoload.php' },
	{ from: 'vendor/composer/ClassLoader.php' },
	{ from: 'vendor/composer/LICENSE' },
	{ from: 'vendor/composer/autoload_real.php' },
	{ from: 'vendor/composer/autoload_static.php' },
	{ from: 'vendor/composer/autoload_classmap.php' },
	{ from: 'vendor/composer/autoload_namespaces.php' },
	{ from: 'vendor/composer/autoload_psr4.php' },
	{ from: 'vendor/composer/platform_check.php' },
];

/**
 * Fail with a message and a way forward.
 *
 * @param {string} message What is wrong.
 * @param {string} [fix]   What to do about it.
 */
function refuse( message, fix ) {
	process.stderr.write( `\nRefusing to build the zip: ${ message }\n` );

	if ( fix ) {
		process.stderr.write( `\n  ${ fix }\n` );
	}

	process.stderr.write( '\n' );
	process.exit( 1 );
}

/**
 * The plugin version, read from the one place that defines it.
 *
 * @return {string} Version.
 */
function version() {
	const header = fs.readFileSync( path.join( ROOT, 'debloater.php' ), 'utf8' );
	const matched = /^\s*\*\s*Version:\s*(\S+)/m.exec( header );

	if ( ! matched ) {
		refuse( 'debloater.php has no Version header.' );
	}

	const readme = fs.readFileSync( path.join( ROOT, 'readme.txt' ), 'utf8' );
	const stable = /^Stable tag:\s*(\S+)/m.exec( readme );

	if ( ! stable ) {
		refuse( 'readme.txt has no Stable tag.' );
	}

	if ( stable[ 1 ] !== matched[ 1 ] ) {
		refuse(
			`the plugin header says ${ matched[ 1 ] } and readme.txt says ${ stable[ 1 ] }.`,
			'These have to agree: wordpress.org serves whichever one it reads first.'
		);
	}

	return matched[ 1 ];
}

/**
 * Refuse a zip whose admin UI was never built.
 */
function requireBuild() {
	const built = path.join( ROOT, 'build' );

	if ( ! fs.existsSync( built ) || 0 === fs.readdirSync( built ).length ) {
		refuse( 'build/ is empty, so the admin screen would be blank.', 'npm run build' );
	}
}

/**
 * Refuse an autoloader that was generated with dev dependencies installed.
 */
function requireProductionAutoloader() {
	const autoload = path.join( ROOT, 'vendor', 'autoload.php' );

	if ( ! fs.existsSync( autoload ) ) {
		refuse( 'vendor/autoload.php is missing.', 'composer install --no-dev' );
	}

	// Only the maps that ship. `installed.php` is left out of the zip, so what
	// it says about dev dependencies is not this check's business.
	const maps = [ 'autoload_psr4.php', 'autoload_static.php', 'autoload_classmap.php' ];

	for ( const file of maps ) {
		const where = path.join( ROOT, 'vendor', 'composer', file );

		if ( ! fs.existsSync( where ) ) {
			continue;
		}

		const contents = fs.readFileSync( where, 'utf8' );

		// Any of these means the autoloader describes files that are not in the
		// zip. `Debloater\Tests` is our own dev namespace; the rest are the dev
		// dependencies that would have been mapped alongside it.
		for ( const marker of [ 'Tests\\\\', 'phpunit', 'PHPUnit', 'PHPCSUtils', 'PHPStan', 'Yoast' ] ) {
			if ( contents.includes( marker ) ) {
				refuse(
					`vendor/composer/${ file } mentions ${ marker }, so the autoloader was ` +
						'generated with dev dependencies installed.',
					'composer install --no-dev --classmap-authoritative'
				);
			}
		}
	}
}

/**
 * Copy one entry of the ship list into the staging directory.
 *
 * @param {string}                     from   Path relative to the repository root.
 * @param {(rel: string) => boolean=} filter  Optional per-file predicate.
 */
function stage( from, filter ) {
	const source = path.join( ROOT, from );
	const target = path.join( STAGE, from );

	if ( ! fs.existsSync( source ) ) {
		refuse( `${ from } is in the ship list but not in the repository.` );
	}

	if ( fs.statSync( source ).isFile() ) {
		fs.mkdirSync( path.dirname( target ), { recursive: true } );
		fs.copyFileSync( source, target );

		return;
	}

	fs.cpSync( source, target, {
		recursive: true,
		filter: ( entry ) => {
			// Never a dotfile. `.gitkeep` markers were shipping inside src/,
			// and wordpress.org rejects hidden files outright — but the better
			// reason is that a dotfile in a release is always an accident:
			// it exists to say something to the repository, not to the site.
			if ( path.basename( entry ).startsWith( '.' ) ) {
				return false;
			}

			if ( ! filter ) {
				return true;
			}

			const relative = path.relative( source, entry ).split( path.sep ).join( '/' );

			return '' === relative || fs.statSync( entry ).isDirectory() || filter( relative );
		},
	} );
}

/**
 * Every file under a directory, relative to it, sorted.
 *
 * @param {string} directory Directory to walk.
 * @return {string[]} Relative paths.
 */
function walk( directory ) {
	const found = [];

	for ( const entry of fs.readdirSync( directory, { withFileTypes: true, recursive: true } ) ) {
		if ( entry.isFile() ) {
			const parent = path.relative( directory, entry.parentPath || entry.path );

			found.push( path.join( parent, entry.name ).split( path.sep ).join( '/' ) );
		}
	}

	return found.sort();
}

/**
 * Refuse a staged tree that contains something it should not.
 *
 * The allow-list makes this close to impossible, which is exactly why it is
 * worth checking: an assertion that never fires costs nothing, and this is the
 * last point at which a mistake is still cheap.
 */
function auditStage() {
	const files = walk( STAGE );

	const forbidden = [
		{ test: ( f ) => f.startsWith( 'tests/' ), why: 'test files' },
		{ test: ( f ) => f.endsWith( '.map' ), why: 'source maps' },
		{ test: ( f ) => f.includes( 'node_modules/' ), why: 'node_modules' },
		{ test: ( f ) => f.endsWith( '.pem' ) || f.endsWith( '.key' ), why: 'a key file' },
		{ test: ( f ) => '.env' === f || f.endsWith( '/.env' ), why: 'an environment file' },
		{ test: ( f ) => f.startsWith( '.git' ), why: 'version-control metadata' },
	];

	for ( const file of files ) {
		for ( const rule of forbidden ) {
			if ( rule.test( file ) ) {
				refuse( `the staged tree contains ${ rule.why }: ${ file }` );
			}
		}
	}

	// And no private key material, wherever it came from.
	for ( const file of files ) {
		if ( ! /\.(php|json|txt|js|pot|po)$/.test( file ) ) {
			continue;
		}

		const contents = fs.readFileSync( path.join( STAGE, file ), 'utf8' );

		if ( /-----BEGIN [A-Z ]*PRIVATE KEY-----/.test( contents ) ) {
			refuse( `${ file } contains what looks like a private key (§13 rule 15).` );
		}
	}

	return files;
}

requireBuild();
requireProductionAutoloader();

const tag = version();

fs.rmSync( STAGE, { recursive: true, force: true } );
fs.mkdirSync( STAGE, { recursive: true } );

for ( const entry of SHIP ) {
	stage( entry.from, entry.filter );
}

const files = auditStage();
const archive = path.join( DIST, `${ SLUG }-${ tag }.zip` );

fs.rmSync( archive, { force: true } );

// PowerShell's Compress-Archive on Windows, `zip` everywhere else. Both are
// present on their respective platforms without installing anything, which
// matters for a step that has to work on a fresh checkout.
if ( 'win32' === process.platform ) {
	execFileSync(
		'powershell',
		[
			'-NoProfile',
			'-Command',
			`Compress-Archive -Path '${ STAGE }' -DestinationPath '${ archive }' -Force`,
		],
		{ stdio: 'inherit' }
	);
} else {
	execFileSync( 'zip', [ '-rq', archive, SLUG ], { cwd: DIST, stdio: 'inherit' } );
}

const size = fs.statSync( archive ).size;

process.stdout.write(
	`\n${ path.relative( ROOT, archive ) }\n` +
		`  ${ files.length } files, ${ ( size / 1024 ).toFixed( 0 ) } KB\n\n`
);
