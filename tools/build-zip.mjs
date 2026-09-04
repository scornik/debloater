#!/usr/bin/env node
/**
 * Build a distributable zip, identically on every platform.
 *
 * BUILD-SPEC §17 Phase 18, and Phase 18b after this shipped a zip that could
 * not be installed.
 *
 * ## The bug this exists to prevent
 *
 * The first version shelled out: `Compress-Archive` on Windows, `zip`
 * everywhere else. `Compress-Archive` under Windows PowerShell 5.1 writes
 * entry names with **backslash** separators — `debloater\debloater.php`. That
 * is legal in the container format and wrong for every consumer: the zip
 * specification says path separators are forward slashes, and a Windows tool
 * ignoring that produces an archive only Windows can read.
 *
 * On a Linux host WordPress extracts `debloater\debloater.php` as one flat file
 * whose *name* contains a backslash. The plugin directory is then empty, and
 * activation fails with "Plugin file does not exist."
 *
 * It is worth recording how this passed a review that thought it had checked.
 * Python's `zipfile.namelist()` — and most zip readers — normalise backslashes
 * to forward slashes on read, so the obvious verification reported zero
 * offending entries on an archive where all 302 were wrong. The check has to
 * read the central directory bytes, which is what `tests/packaging/` does.
 *
 * ## Rules
 *
 * - **Never shell out.** No PowerShell, no `zip`, no OS tool. `archiver` writes
 *   the bytes in-process, so the output does not depend on the machine.
 * - **Forward slashes, always.** Entry names are joined with `/` literally,
 *   never with `path.join`, which is `\` on Windows.
 * - **Explicit directory entries.** Not required by the format, but some
 *   extractors rely on them, and their absence is the other half of how an
 *   archive ends up flat.
 * - **One top-level folder,** named for the slug: that is the directory
 *   WordPress installs into.
 * - **An allow-list decides what ships,** not a deny-list. `.distignore` is
 *   applied on top, for the WordPress tooling that reads it.
 */

import { ZipArchive } from 'archiver';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import url from 'node:url';

const ROOT = path.resolve( path.dirname( url.fileURLToPath( import.meta.url ) ), '..' );
const DIST = path.join( ROOT, 'dist' );

/**
 * The two plugins this repository builds.
 *
 * Pro is a separate plugin and a separate zip, never inside the free one.
 */
const PLUGINS = {
	free: {
		slug: 'debloater',
		root: ROOT,
		entry: 'debloater.php',
		requiresBuild: true,
		ship: [
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

			// The built admin UI, without source maps: a development
			// convenience several times the size of the code it describes.
			{ from: 'build', filter: ( rel ) => ! rel.endsWith( '.map' ) },

			// The generated autoloader, and only that. Not the whole of
			// `vendor/composer/`: `installed.php`, `installed.json` and
			// `InstalledVersions.php` are Composer's package inventory, nothing
			// here reads them, and shipping them would put a list of forty dev
			// dependencies into a zip containing none of them.
			{ from: 'vendor/autoload.php' },
			{ from: 'vendor/composer/ClassLoader.php' },
			{ from: 'vendor/composer/LICENSE' },
			{ from: 'vendor/composer/autoload_real.php' },
			{ from: 'vendor/composer/autoload_static.php' },
			{ from: 'vendor/composer/autoload_classmap.php' },
			{ from: 'vendor/composer/autoload_namespaces.php' },
			{ from: 'vendor/composer/autoload_psr4.php' },
			{ from: 'vendor/composer/platform_check.php' },
		],
	},

	pro: {
		slug: 'debloater-pro',
		root: path.join( ROOT, 'pro' ),
		entry: 'debloater-pro.php',
		requiresBuild: false,
		ship: [ { from: 'debloater-pro.php' }, { from: 'src' } ],
	},
};

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
 * Patterns from `.distignore`, as plain entries.
 *
 * WordPress tooling reads this file, so it is honoured here rather than left
 * to disagree with the allow-list. The allow-list decides; this can only ever
 * remove.
 *
 * @return {string[]} Entries.
 */
function distignore() {
	const file = path.join( ROOT, '.distignore' );

	if ( ! fs.existsSync( file ) ) {
		return [];
	}

	return fs
		.readFileSync( file, 'utf8' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => '' !== line && ! line.startsWith( '#' ) );
}

/**
 * Whether `.distignore` excludes a path.
 *
 * @param {string[]} patterns Entries from `.distignore`.
 * @param {string}   relative Forward-slash path relative to the plugin root.
 * @return {boolean} True when it must not ship.
 */
function ignored( patterns, relative ) {
	return patterns.some( ( pattern ) => {
		const clean = pattern.replace( /^\/+|\/+$/g, '' );

		if ( '' === clean ) {
			return false;
		}

		if ( clean.startsWith( '*' ) ) {
			return relative.endsWith( clean.slice( 1 ) );
		}

		return relative === clean || relative.startsWith( `${ clean }/` );
	} );
}

/**
 * Every file under a directory, as forward-slash paths relative to it.
 *
 * Sorted, so two builds of the same tree produce the same archive.
 *
 * @param {string} directory Absolute path.
 * @return {string[]} Relative POSIX paths.
 */
function walk( directory ) {
	const found = [];

	for ( const entry of fs.readdirSync( directory, { withFileTypes: true, recursive: true } ) ) {
		if ( ! entry.isFile() ) {
			continue;
		}

		const parent = path.relative( directory, entry.parentPath || entry.path );

		found.push( path.join( parent, entry.name ).split( path.sep ).join( '/' ) );
	}

	return found.sort();
}

/**
 * Resolve one plugin's ship list to POSIX paths relative to its root.
 *
 * @param {object}   plugin   Plugin definition.
 * @param {string[]} patterns `.distignore` entries.
 * @return {string[]} Relative POSIX paths, sorted.
 */
function collect( plugin, patterns ) {
	const files = new Set();

	for ( const entry of plugin.ship ) {
		const source = path.join( plugin.root, entry.from );

		if ( ! fs.existsSync( source ) ) {
			refuse( `${ entry.from } is in the ship list but not in the repository.` );
		}

		if ( fs.statSync( source ).isFile() ) {
			files.add( entry.from );

			continue;
		}

		for ( const relative of walk( source ) ) {
			// Never a dotfile. `.gitkeep` markers were shipping inside src/,
			// and wordpress.org rejects hidden files — but the better reason is
			// that a dotfile in a release is always an accident: it exists to
			// say something to the repository, not to the site.
			if ( relative.split( '/' ).some( ( part ) => part.startsWith( '.' ) ) ) {
				continue;
			}

			if ( entry.filter && ! entry.filter( relative ) ) {
				continue;
			}

			files.add( `${ entry.from }/${ relative }` );
		}
	}

	return [ ...files ].filter( ( file ) => ! ignored( patterns, file ) ).sort();
}

/**
 * Refuse a file list containing something that must never ship.
 *
 * The allow-list makes this close to impossible, which is exactly why it is
 * worth checking: an assertion that never fires costs nothing, and this is the
 * last point at which a mistake is still cheap.
 *
 * @param {object}   plugin Plugin definition.
 * @param {string[]} files  Relative POSIX paths.
 */
function audit( plugin, files ) {
	const forbidden = [
		{ test: ( f ) => f.startsWith( 'tests/' ), why: 'test files' },
		{ test: ( f ) => f.includes( 'node_modules/' ), why: 'node_modules' },
		{ test: ( f ) => f.startsWith( '.github' ), why: 'CI configuration' },
		{ test: ( f ) => f.endsWith( '.map' ), why: 'source maps' },
		{ test: ( f ) => '.wp-env.json' === f, why: 'the local environment config' },
		{ test: ( f ) => f.endsWith( '.pem' ) || f.endsWith( '.key' ), why: 'a key file' },
		{ test: ( f ) => f.split( '/' ).some( ( p ) => p.startsWith( '.' ) ), why: 'a hidden file' },
	];

	for ( const file of files ) {
		for ( const rule of forbidden ) {
			if ( rule.test( file ) ) {
				refuse( `${ plugin.slug } would ship ${ rule.why }: ${ file }` );
			}
		}
	}

	for ( const file of files ) {
		if ( ! /\.(php|json|txt|js|pot|po)$/.test( file ) ) {
			continue;
		}

		const contents = fs.readFileSync( path.join( plugin.root, file ), 'utf8' );

		if ( /-----BEGIN [A-Z ]*PRIVATE KEY-----/.test( contents ) ) {
			refuse( `${ file } contains what looks like a private key (§13 rule 15).` );
		}
	}
}

/**
 * The version from a plugin header, checked against readme.txt where there is one.
 *
 * @param {object} plugin Plugin definition.
 * @return {string} Version.
 */
function version( plugin ) {
	const header = fs.readFileSync( path.join( plugin.root, plugin.entry ), 'utf8' );
	const matched = /^\s*\*\s*Version:\s*(\S+)/m.exec( header );

	if ( ! matched ) {
		refuse( `${ plugin.entry } has no Version header.` );
	}

	const readme = path.join( plugin.root, 'readme.txt' );

	if ( fs.existsSync( readme ) ) {
		const stable = /^Stable tag:\s*(\S+)/m.exec( fs.readFileSync( readme, 'utf8' ) );

		if ( ! stable ) {
			refuse( 'readme.txt has no Stable tag.' );
		}

		if ( stable[ 1 ] !== matched[ 1 ] ) {
			refuse(
				`the plugin header says ${ matched[ 1 ] } and readme.txt says ${ stable[ 1 ] }.`,
				'These have to agree: wordpress.org serves whichever one it reads first.'
			);
		}
	}

	return matched[ 1 ];
}

/**
 * Refuse an autoloader generated with dev dependencies installed.
 *
 * @param {object} plugin Plugin definition.
 */
function requireProductionAutoloader( plugin ) {
	const autoload = path.join( plugin.root, 'vendor', 'autoload.php' );

	if ( ! fs.existsSync( autoload ) ) {
		refuse( 'vendor/autoload.php is missing.', 'composer install --no-dev' );
	}

	for ( const file of [ 'autoload_psr4.php', 'autoload_static.php', 'autoload_classmap.php' ] ) {
		const where = path.join( plugin.root, 'vendor', 'composer', file );

		if ( ! fs.existsSync( where ) ) {
			continue;
		}

		const contents = fs.readFileSync( where, 'utf8' );

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
 * Write one zip.
 *
 * @param {object}   plugin Plugin definition.
 * @param {string[]} files  Relative POSIX paths.
 * @param {string}   tag    Version.
 * @return {Promise<string>} Path to the archive.
 */
function write( plugin, files, tag ) {
	const archive = path.join( DIST, `${ plugin.slug }-${ tag }.zip` );

	fs.mkdirSync( DIST, { recursive: true } );
	fs.rmSync( archive, { force: true } );

	return new Promise( ( resolve, reject ) => {
		const output = fs.createWriteStream( archive );
		const zip = new ZipArchive( { zlib: { level: 9 } } );

		output.on( 'close', () => resolve( archive ) );
		zip.on( 'warning', reject );
		zip.on( 'error', reject );
		zip.pipe( output );

		// Explicit directory entries, parents before children, so an extractor
		// that relies on them never meets a file before its folder.
		const directories = new Set();

		for ( const file of files ) {
			const parts = file.split( '/' );

			for ( let index = 0; index < parts.length - 1; index++ ) {
				directories.add( parts.slice( 0, index + 1 ).join( '/' ) );
			}
		}

		zip.append( null, { name: `${ plugin.slug }/` } );

		for ( const directory of [ ...directories ].sort() ) {
			zip.append( null, { name: `${ plugin.slug }/${ directory }/` } );
		}

		for ( const file of files ) {
			// Joined with a literal `/`, never `path.join`, which on Windows
			// produces the backslashes this whole file exists to prevent.
			zip.file( path.join( plugin.root, file ), { name: `${ plugin.slug }/${ file }` } );
		}

		zip.finalize();
	} );
}

/**
 * Build one plugin's zip.
 *
 * @param {string} which Key in PLUGINS.
 * @return {Promise<void>}
 */
async function build( which ) {
	const plugin = PLUGINS[ which ];

	if ( plugin.requiresBuild ) {
		const built = path.join( plugin.root, 'build' );

		if ( ! fs.existsSync( built ) || 0 === fs.readdirSync( built ).length ) {
			refuse( 'build/ is empty, so the admin screen would be blank.', 'npm run build' );
		}

		requireProductionAutoloader( plugin );
	}

	const tag = version( plugin );
	const files = collect( plugin, distignore() );

	audit( plugin, files );

	const archive = await write( plugin, files, tag );
	const size = fs.statSync( archive ).size;

	process.stdout.write(
		`\n${ path.relative( ROOT, archive ).split( path.sep ).join( '/' ) }\n` +
			`  ${ files.length } files, ${ ( size / 1024 ).toFixed( 0 ) } KB\n`
	);
}

const requested = process.argv[ 2 ] ?? 'all';
const targets = 'all' === requested ? Object.keys( PLUGINS ) : [ requested ];

for ( const target of targets ) {
	if ( ! PLUGINS[ target ] ) {
		refuse( `Unknown plugin "${ target }". Use one of: ${ Object.keys( PLUGINS ).join( ', ' ) }, all.` );
	}
}

for ( const target of targets ) {
	await build( target );
}

process.stdout.write( '\n' );
