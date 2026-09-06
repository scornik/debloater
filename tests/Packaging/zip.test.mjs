/**
 * The zip has to be installable, on a machine that is not this one.
 *
 * BUILD-SPEC §17 Phase 18b.
 *
 * ## Why this file exists
 *
 * Phase 18 shipped `debloater-0.1.0.zip` and reported it verified. Every one of
 * its 302 entries used a **backslash** separator, because the build shelled out
 * to `Compress-Archive` under Windows PowerShell 5.1. On a Linux host WordPress
 * extracts `debloater\debloater.php` as one flat file whose name contains a
 * backslash, the plugin directory ends up empty, and activation fails with
 * "Plugin file does not exist."
 *
 * The verification that missed it is the instructive part. Python's
 * `zipfile.namelist()` — and most zip readers, and `unzip -l` on some
 * platforms — **normalise backslashes to forward slashes when reading**. So the
 * obvious check reported zero offending entries on an archive where every entry
 * was wrong.
 *
 * Every assertion here therefore parses the **central directory bytes** rather
 * than asking a library what the names are. A test that reads through the same
 * normalisation as the bug cannot see the bug.
 *
 * ## The install test, and a directory it must never name
 *
 * The last test installs the archive into a real WordPress, because "the bytes
 * look right" is a weaker claim than "WordPress activated it".
 *
 * It installs under a **throwaway slug**, and that is not fastidiousness. This
 * repository is bind-mounted into wp-env at
 * `wp-content/plugins/debloater` (see `.wp-env.json`). An earlier version of
 * this file ran `wp plugin delete debloater` to reach a clean install state,
 * and WP-CLI deleted the plugin directory *through the mount* — which is to say
 * it deleted the working tree, including `.git`. The repository had to be
 * restored from the remote.
 *
 * So: `assertNotMapped()` reads `.wp-env.json` and refuses to run any
 * destructive WP-CLI command against a slug that is mapped to a real directory.
 * The rule is enforced rather than remembered, because remembering is what
 * failed.
 */

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import test from 'node:test';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import url from 'node:url';

const ROOT = path.resolve( path.dirname( url.fileURLToPath( import.meta.url ) ), '..', '..' );
const DIST = path.join( ROOT, 'dist' );

const BACKSLASH = Buffer.from( [ 0x5c ] );

// Read, not written down. A hard-coded version means every release breaks
// this file, and the obvious fix is a search-and-replace that also rewrites
// the 0.1.0 in the history above.
const VERSION = JSON.parse(
	fs.readFileSync( path.join( ROOT, 'package.json' ), 'utf8' )
).version;

/**
 * The plugins this repository ships, and what each zip must contain.
 *
 * `installAs` is deliberately not the real slug. See the note above.
 */
const PLUGINS = [
	{
		key: 'free',
		slug: 'debloater',
		installAs: 'debloater-pkgtest',
		entry: 'debloater.php',
		name: 'Debloater',
		textDomain: 'debloater',
		requiresPlugins: null,
		mustContain: [ 'src/Plugin.php', 'readme.txt', 'uninstall.php', 'vendor/autoload.php' ],
		mustNotContain: [ 'src/Pro.php', 'pro/' ],
	},
];

/**
 * Entry names, read from the central directory as raw bytes.
 *
 * Deliberately not `yauzl`, `adm-zip` or `unzip -l`: they normalise
 * separators, which is the one thing being tested.
 *
 * @param {string} archive Path to the zip.
 * @return {Buffer[]} Entry names, exactly as stored.
 */
function rawEntryNames( archive ) {
	const data = fs.readFileSync( archive );
	const names = [];

	let position = 0;

	for ( ;; ) {
		position = data.indexOf( 'PK\x01\x02', position, 'latin1' );

		if ( position < 0 ) {
			break;
		}

		const length = data.readUInt16LE( position + 28 );

		names.push( data.subarray( position + 46, position + 46 + length ) );
		position += 4;
	}

	return names;
}

/**
 * One entry's contents, read from the archive directly.
 *
 * Written out rather than delegated, for the same reason the names are: a zip
 * library would work and would also be one more thing between this test and
 * the bytes it exists to check.
 *
 * Sizes and the local-header offset come from the **central directory**, not
 * from the local header. `archiver` writes entries as a stream, so the local
 * header carries a compressed size of zero and the real figure arrives in a
 * data descriptor *after* the payload. Reading the local header's zero and
 * handing that to `inflateRawSync` produces `Z_BUF_ERROR`, which is how this
 * was found.
 *
 * @param {string} archive Path to the zip.
 * @param {string} entry   Entry name, exactly as stored.
 * @return {string} Contents.
 */
function readBytes( archive, entry ) {
	const data = fs.readFileSync( archive );
	const wanted = Buffer.from( entry, 'utf8' );

	let position = 0;

	for ( ;; ) {
		position = data.indexOf( 'PK', position, 'latin1' );

		if ( position < 0 ) {
			throw new Error( `${ entry } is not in ${ path.basename( archive ) }` );
		}

		const nameLength = data.readUInt16LE( position + 28 );
		const name = data.subarray( position + 46, position + 46 + nameLength );

		if ( name.equals( wanted ) ) {
			const method = data.readUInt16LE( position + 10 );
			const compressed = data.readUInt32LE( position + 20 );
			const localOffset = data.readUInt32LE( position + 42 );

			// The local header repeats the name and extra fields, and its extra
			// field length can differ from the central directory's, so both are
			// read from the local header itself.
			const localNameLength = data.readUInt16LE( localOffset + 26 );
			const localExtraLength = data.readUInt16LE( localOffset + 28 );
			const start = localOffset + 30 + localNameLength + localExtraLength;
			const body = data.subarray( start, start + compressed );

			return 0 === method ? body : zlib.inflateRawSync( body );
		}

		position += 4;
	}
}

/**
 * One entry's contents, as text.
 *
 * @param {string} archive Path to the zip.
 * @param {string} entry   Entry name.
 * @return {string} The contents.
 */
function read( archive, entry ) {
	return readBytes( archive, entry ).toString( 'utf8' );
}

/**
 * Every plugin slug wp-env maps to a real directory on this machine.
 *
 * @return {Set<string>} Slugs.
 */
function mappedSlugs() {
	const config = path.join( ROOT, '.wp-env.json' );
	const slugs = new Set();

	if ( ! fs.existsSync( config ) ) {
		return slugs;
	}

	const parsed = JSON.parse( fs.readFileSync( config, 'utf8' ) );

	const gather = ( mappings ) => {
		for ( const target of Object.keys( mappings ?? {} ) ) {
			const parts = target.split( '/' );

			if ( 'plugins' === parts[ parts.length - 2 ] ) {
				slugs.add( parts[ parts.length - 1 ] );
			}
		}
	};

	gather( parsed.mappings );

	for ( const environment of Object.values( parsed.env ?? {} ) ) {
		gather( environment.mappings );
	}

	return slugs;
}

/**
 * Refuse to touch a slug that is a bind mount of a real directory.
 *
 * `wp plugin delete <slug>` removes `wp-content/plugins/<slug>` recursively.
 * When that path is a mapping, the thing removed is the mapped directory — and
 * for this repository the mapped directory is the repository. That is not a
 * hypothetical: it happened, and the working tree had to be restored from the
 * remote.
 *
 * @param {string} slug Slug about to be installed or deleted.
 */
function assertNotMapped( slug ) {
	assert.equal(
		mappedSlugs().has( slug ),
		false,
		`Refusing to install or delete "${ slug }": .wp-env.json maps it to a real ` +
			'directory, and WP-CLI would delete that directory through the mount.'
	);
}

test( 'the zips build', () => {
	fs.rmSync( DIST, { recursive: true, force: true } );

	// The build refuses a dev autoloader, on purpose. Surfaced here rather than
	// left to fail six assertions later as "unzip: can't open ...", which is a
	// true error message about the wrong thing.
	try {
		execFileSync( process.execPath, [ path.join( ROOT, 'scripts', 'plugin-zip.mjs' ), 'all' ], {
			cwd: ROOT,
			stdio: 'inherit',
		} );
	} catch ( error ) {
		assert.fail(
			'The zips did not build. This suite needs a production autoloader, ' +
				'the same one a release is built from:\n\n' +
				'  composer install --no-dev --classmap-authoritative\n\n' +
				'and afterwards, to get the dev one back:\n\n' +
				'  composer install\n\n' +
				`Original failure: ${ error.message }`
		);
	}

	for ( const plugin of PLUGINS ) {
		assert.ok(
			fs.existsSync( path.join( DIST, `${ plugin.slug }-${ VERSION }.zip` ) ),
			`${ plugin.slug } did not produce a zip`
		);
	}
} );

for ( const plugin of PLUGINS ) {
	const archive = () => path.join( DIST, `${ plugin.slug }-${ VERSION }.zip` );

	test( `${ plugin.slug }: no entry name contains a backslash`, () => {
		const names = rawEntryNames( archive() );

		assert.ok( names.length > 0, 'the archive has no entries at all' );

		const offending = names.filter( ( name ) => name.includes( BACKSLASH ) );

		assert.deepEqual(
			offending.map( ( n ) => n.toString( 'latin1' ) ),
			[],
			'Backslash separators make the archive unextractable on Linux. Most zip ' +
				'readers hide this by normalising on read, which is why this assertion ' +
				'parses the central directory itself.'
		);
	} );

	test( `${ plugin.slug }: exactly one top-level folder, named for the slug`, () => {
		const names = rawEntryNames( archive() ).map( ( n ) => n.toString( 'utf8' ) );
		const tops = new Set( names.map( ( n ) => n.split( '/' )[ 0 ] ) );

		assert.deepEqual( [ ...tops ], [ plugin.slug ] );
	} );

	test( `${ plugin.slug }: has explicit directory entries`, () => {
		const names = rawEntryNames( archive() ).map( ( n ) => n.toString( 'utf8' ) );
		const directories = names.filter( ( n ) => n.endsWith( '/' ) );

		assert.ok( directories.includes( `${ plugin.slug }/` ), 'the plugin folder must be an entry' );
		assert.ok( directories.length > 1, 'nested directories must be entries too' );
	} );

	test( `${ plugin.slug }: the entry point is at the root with a valid header`, () => {
		const names = rawEntryNames( archive() ).map( ( n ) => n.toString( 'utf8' ) );
		const entry = `${ plugin.slug }/${ plugin.entry }`;

		assert.ok( names.includes( entry ), `${ entry } is missing` );

		const header = read( archive(), entry );

		assert.match( header, /^\s*\*\s*Version:\s*\d+\.\d+\.\d+$/m );
		assert.match( header, /^\s*\*\s*Requires PHP:\s*8\.1$/m );
		assert.match( header, /^\s*\*\s*Requires at least:\s*6\.5$/m );

		const name = /^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m.exec( header )[ 1 ];

		assert.equal(
			name,
			plugin.name,
			'wordpress.org derives the slug from this header, so it must be exact.'
		);

		// The text domain must equal the slug. wordpress.org serves
		// translations against the slug, so a domain that differs from it is a
		// plugin whose translations are never delivered — and nothing about
		// the plugin looks wrong while that is true.
		const domain = /^\s*\*\s*Text Domain:\s*(.+?)\s*$/m.exec( header );

		assert.ok( domain, `${ plugin.entry } has no Text Domain header` );
		assert.equal( domain[ 1 ], plugin.textDomain );
		assert.equal( domain[ 1 ], plugin.slug, 'the text domain must equal the slug' );

		const requires = /^\s*\*\s*Requires Plugins:\s*(.+?)\s*$/m.exec( header );

		if ( null === plugin.requiresPlugins ) {
			// The free plugin depends on nothing. A `Requires Plugins` header
			// here would make Debloater unactivatable without whatever it
			// named, which is the opposite of what free means.
			assert.equal( requires, null, 'the free plugin must require no other plugin' );
		} else {
			assert.ok( requires, `${ plugin.entry } is missing Requires Plugins` );
			assert.equal( requires[ 1 ], plugin.requiresPlugins );
		}
	} );

	test( `${ plugin.slug }: ships nothing it should not`, () => {
		const names = rawEntryNames( archive() ).map( ( n ) => n.toString( 'utf8' ) );

		const forbidden = [
			{ label: 'tests/', test: ( n ) => n.includes( '/tests/' ) },
			{ label: 'node_modules', test: ( n ) => n.includes( 'node_modules' ) },
			{ label: '.wp-env.json', test: ( n ) => n.endsWith( '.wp-env.json' ) },
			{ label: '.github', test: ( n ) => n.includes( '.github' ) },
			{ label: 'a dotfile', test: ( n ) => n.split( '/' ).some( ( p ) => p.startsWith( '.' ) ) },
			{ label: 'a source map', test: ( n ) => n.endsWith( '.map' ) },
			{ label: 'phpunit', test: ( n ) => n.toLowerCase().includes( 'phpunit' ) },
			{ label: 'phpcs', test: ( n ) => n.toLowerCase().includes( 'phpcs' ) },
			{ label: 'phpstan', test: ( n ) => n.toLowerCase().includes( 'phpstan' ) },
			{ label: 'composer.lock', test: ( n ) => n.endsWith( 'composer.lock' ) },
		];

		for ( const rule of forbidden ) {
			assert.deepEqual( names.filter( rule.test ), [], `${ plugin.slug } ships ${ rule.label }` );
		}

		for ( const wanted of plugin.mustContain ) {
			assert.ok( names.includes( `${ plugin.slug }/${ wanted }` ), `${ wanted } should be in the zip` );
		}

		for ( const unwanted of plugin.mustNotContain ) {
			assert.equal(
				names.some( ( n ) => n.startsWith( `${ plugin.slug }/${ unwanted }` ) ),
				false,
				`${ unwanted } should not be in the ${ plugin.slug } zip`
			);
		}
	} );

	test( `${ plugin.slug }: carries no licensing platform`, () => {
		const names = rawEntryNames( archive() ).map( ( n ) => n.toString( 'utf8' ) );

		if ( 'free' !== plugin.key ) {
			// Pro is where a licensing SDK belongs, and Part 2 puts one there.
			// Asserting its absence here would be asserting that Part 2 has not
			// happened yet, which is a test with a shelf life.
			return;
		}

		// BUILD-SPEC §13 rule 15, and the part of it that is easiest to break by
		// accident: free Debloater is fully functional with no Pro, no
		// licensing platform and no cloud. A licensing SDK inside the free zip
		// would contradict that whether or not any code called it — the check
		// is on the package, because the package is what a reviewer reads.
		const vendored = names.filter( ( n ) => n.toLowerCase().includes( 'freemius' ) );

		assert.deepEqual( vendored, [], 'the free zip must contain no licensing SDK' );

		// And no call into one, in any file that ships. Read from the archive
		// rather than the working tree: what is on disk is not what was
		// packaged, and only one of the two gets installed.
		for ( const name of names ) {
			if ( name.endsWith( '/' ) || ! name.endsWith( '.php' ) ) {
				continue;
			}

			const contents = read( archive(), name );

			assert.equal(
				contents.includes( 'fs_dynamic_init' ),
				false,
				`${ name } initialises a licensing SDK, in the free plugin`
			);
		}
	} );

	test( `${ plugin.slug }: carries no dev Composer packages`, () => {
		const names = rawEntryNames( archive() ).map( ( n ) => n.toString( 'utf8' ) );
		const vendor = names.filter( ( n ) => n.includes( '/vendor/' ) && ! n.endsWith( '/' ) );

		// Named exactly, not matched by pattern. A pattern is how
		// `autoload_psr4.php` slipped past `autoload_[a-z]+` — and the list of
		// files Composer's generated autoloader consists of is short, fixed and
		// worth being able to read.
		const allowed = new Set( [
			'vendor/autoload.php',
			'vendor/composer/LICENSE',
			'vendor/composer/ClassLoader.php',
			'vendor/composer/platform_check.php',
			'vendor/composer/autoload_real.php',
			'vendor/composer/autoload_static.php',
			'vendor/composer/autoload_classmap.php',
			'vendor/composer/autoload_namespaces.php',
			'vendor/composer/autoload_psr4.php',
		] );

		for ( const name of vendor ) {
			const relative = name.slice( `${ plugin.slug }/`.length );

			assert.ok(
				allowed.has( relative ),
				`${ relative } is in vendor/ but is not part of the generated autoloader`
			);
		}

		const psr4 = vendor.find( ( n ) => n.endsWith( 'autoload_psr4.php' ) );

		if ( psr4 ) {
			const contents = read( archive(), psr4 );

			for ( const marker of [ 'Tests', 'phpunit', 'PHPStan', 'Yoast' ] ) {
				assert.equal(
					contents.includes( marker ),
					false,
					`the autoloader maps ${ marker }, which is not in the zip`
				);
			}
		}
	} );
}

/**
 * The archive is a function of its contents, and nothing else.
 *
 * It was not. A zip stores each entry's modification time, and `composer
 * install` rewrites `vendor/` mtimes, so every release build produced different
 * bytes from identical sources. Worse, entries were appended in whatever order
 * the filesystem answered in, so the same 340 files landed in different
 * positions each time.
 *
 * That made the published checksum a statement about one build rather than
 * about one plugin — it could not be used to answer "is this the code you
 * reviewed", which is the only question anybody asks a checksum of a plugin
 * zip.
 */
test( 'the free zip builds byte for byte the same twice', () => {
	const archive = path.join( DIST, `debloater-${ VERSION }.zip` );
	const first = crypto.createHash( 'sha256' ).update( fs.readFileSync( archive ) ).digest( 'hex' );

	execFileSync( process.execPath, [ path.join( ROOT, 'scripts', 'plugin-zip.mjs' ), 'free' ], {
		cwd: ROOT,
		stdio: 'ignore',
	} );

	const second = crypto.createHash( 'sha256' ).update( fs.readFileSync( archive ) ).digest( 'hex' );

	assert.equal(
		second,
		first,
		'Two builds of an unchanged tree must produce identical bytes, or the checksum ' +
			'identifies a build rather than a plugin.'
	);
} );

/**
 * Nothing reaches the zip that nobody meant to put there.
 *
 * `free-plugin-content.json` records every entry's content hash. It began as
 * proof about the Pro split: the brief asked for an identical SHA-256 before
 * and after, which was impossible — the build was not reproducible then, so
 * that hash differed between two builds of the same commit. Byte identity was
 * the wrong invariant. The question worth asking was whether the plugin people
 * install had changed, and the answer was recorded file by file.
 *
 * That question is settled, and the file has outlived it. What it does now is
 * the more useful thing: a release that adds, removes or alters a shipped file
 * fails here and names the file. A build artefact, a stray fixture or a
 * dependency that quietly grew a directory shows up as an addition nobody
 * wrote down.
 *
 * So a diff here is not a failure to fix by regenerating on reflex. Regenerate
 * it in the commit that changed the shipped code, and say in the file's own
 * comment what changed and why — the record is the point of it.
 */
test( 'the free zip ships exactly the content recorded', () => {
	const recorded = JSON.parse(
		fs.readFileSync( path.join( ROOT, 'tests', 'Packaging', 'free-plugin-content.json' ), 'utf8' )
	);

	const archive = path.join( DIST, `debloater-${ VERSION }.zip` );
	const names = rawEntryNames( archive ).map( ( n ) => n.toString( 'utf8' ) );

	const built = {};

	for ( const name of names ) {
		if ( name.endsWith( '/' ) ) {
			continue;
		}

		// The bytes, not the text. Decoding to a string first and hashing that
		// reports every file containing a multi-byte character as altered,
		// which is a statement about the decoder rather than the archive.
		built[ name ] = crypto.createHash( 'sha256' ).update( readBytes( archive, name ) ).digest( 'hex' );
	}

	assert.equal(
		Object.keys( built ).length,
		recorded.entry_count,
		`The zip ships ${ Object.keys( built ).length } files; ${ recorded.entry_count } were recorded.`
	);

	const added = Object.keys( built ).filter( ( n ) => ! ( n in recorded.entries ) );
	const gone = Object.keys( recorded.entries ).filter( ( n ) => ! ( n in built ) );
	const changed = Object.keys( built ).filter(
		( n ) => n in recorded.entries && built[ n ] !== recorded.entries[ n ]
	);

	assert.deepEqual( added, [], 'files added to the zip since it was recorded' );
	assert.deepEqual( gone, [], 'files no longer in the zip' );
	assert.deepEqual( changed, [], 'files whose shipped contents changed' );
} );

test( 'the guard refuses a mapped slug', () => {
	// The rule that stops this file destroying the repository again. It has to
	// be asserted, or it is a comment.
	const mapped = mappedSlugs();

	assert.ok( mapped.has( 'debloater' ), '.wp-env.json should map the free plugin' );
	assert.throws(
		() => assertNotMapped( 'debloater' ),
		/Refusing to install or delete/,
		'a mapped slug must be refused'
	);

	for ( const plugin of PLUGINS ) {
		assert.equal(
			mapped.has( plugin.installAs ),
			false,
			`${ plugin.installAs } must not be a mapped directory`
		);
	}
} );

/**
 * The assertion that matters: WordPress extracts and activates it.
 *
 * Everything above checks bytes. This checks what the bytes are for, in a Linux
 * container — where the original bug appeared, and where this machine's own
 * filesystem cannot hide it.
 *
 * Note what it does **not** do. It never runs `wp plugin install` on the
 * archive, because WordPress names the installed directory after the zip's
 * top-level folder — which for the free plugin is `debloater`, which is a bind
 * mount of this repository. `--force` on that path would overwrite the working
 * tree, which is the accident this file already caused once.
 *
 * Instead it extracts with `unzip` and copies the result to a throwaway slug.
 * That is a *better* test of the original bug as well: a zip with backslash
 * entry names extracts to a flat file rather than a directory, so
 * `<dir>/<entry>` simply is not there, and the assertion fails for the right
 * reason.
 *
 * Skipped loudly when wp-env is unavailable. An environment that quietly drops
 * its most important test is worse than one that has none.
 */
test( 'WordPress extracts and activates each zip', { concurrency: 1 }, async ( t ) => {
	// wp-env's own entry point, run with this Node, rather than `npx`.
	//
	// Two dead ends led here. `shell: true` makes Node join the arguments and
	// hand the string to cmd.exe, which re-parses it — so `sh -c "rm -rf a b"`
	// arrives as five separate arguments and `&&` escapes into the outer shell.
	// Naming `npx.cmd` with `shell: false` fixes the quoting and then fails
	// with EINVAL, because current Node refuses to spawn a .cmd without a
	// shell. Calling the JS directly has neither problem and is the same
	// program either way.
	const wpEnvBin = path.join( ROOT, 'node_modules', '@wordpress', 'env', 'bin', 'wp-env' );

	const wpEnv = ( args ) =>
		execFileSync( process.execPath, [ wpEnvBin, ...args ], {
			cwd: ROOT,
			encoding: 'utf8',
			shell: false,
			maxBuffer: 32 * 1024 * 1024,
			stdio: 'pipe',
		} );

	const sh = ( command ) => wpEnv( [ 'run', 'cli', 'sh', '-c', command ] );

	try {
		wpEnv( [ 'run', 'cli', 'wp', 'core', 'version' ] );
	} catch ( error ) {
		t.skip( `wp-env is not running, so extraction was not checked: ${ error.message }` );

		return;
	}

	const plugins = '/var/www/html/wp-content/plugins';

	for ( const plugin of PLUGINS ) {
		// Nothing below may name a mapped directory.
		assertNotMapped( plugin.installAs );

		const zip = `${ plugin.slug }-${ VERSION }.zip`;
		const source = `${ plugins }/debloater/dist/${ zip }`;
		const workspace = `/tmp/pkgtest-${ plugin.installAs }`;
		const target = `${ plugins }/${ plugin.installAs }`;

		sh( `rm -rf ${ workspace } ${ target } && mkdir -p ${ workspace }` );

		// The real test of the original bug: a zip whose entries use backslash
		// separators extracts as flat files, so the directory below is empty
		// and the file assertion fails.
		sh( `unzip -q ${ source } -d ${ workspace }` );

		const listing = sh( `ls -A ${ workspace }` ).trim().split( /\s+/ ).filter( Boolean );

		assert.deepEqual(
			listing,
			[ plugin.slug ],
			`extracting should produce exactly one directory named ${ plugin.slug }`
		);

		const entryPath = `${ workspace }/${ plugin.slug }/${ plugin.entry }`;

		assert.equal(
			sh( `test -f ${ entryPath } && echo yes || echo no` ).trim(),
			'yes',
			`${ plugin.entry } must be a file inside the extracted directory, not part of a flat name`
		);

		assert.equal(
			sh( `find ${ workspace } -name '*\\*' | wc -l` ).trim(),
			'0',
			'nothing extracted may have a backslash in its name'
		);

		// Install it as a plugin WordPress has never seen, under a name that is
		// not a mount of anything.
		sh( `cp -r ${ workspace }/${ plugin.slug } ${ target }` );

		// Two copies of the same plugin cannot be active at once — the classes
		// would be declared twice. So the mapped one steps aside, and is put
		// back afterwards whatever happens.
		const deactivateMapped = 'free' === plugin.key;

		if ( deactivateMapped ) {
			wpEnv( [ 'run', 'cli', 'wp', 'plugin', 'deactivate', plugin.slug ] );
		}

		try {
			wpEnv( [ 'run', 'cli', 'wp', 'plugin', 'activate', plugin.installAs ] );

			const active = wpEnv( [ 'run', 'cli', 'wp', 'plugin', 'is-active', plugin.installAs ] );

			assert.ok(
				! /Error/i.test( active ),
				`${ plugin.installAs } did not activate: ${ active }`
			);

			// And WordPress read the header it was meant to read.
			const listed = wpEnv( [
				'run',
				'cli',
				'wp',
				'plugin',
				'get',
				plugin.installAs,
				'--field=title',
			] );

			assert.equal( listed.trim(), plugin.name );

			// Exactly one plugin row, measured the way WordPress measures it.
			//
			// `get_plugins()` scans the plugins directory one level deep: for
			// each plugin folder it lists the `.php` files *directly inside*
			// it. So `debloater/debloater.php` is a plugin and
			// `debloater/mu-loader/debloater-loader.php` is not, because it is
			// one level further down.
			//
			// That distinction is the whole of the duplicate-plugins bug. The
			// broken archive extracted as flat files whose *names* contained
			// backslashes — `debloater\mu-loader\debloater-loader.php` — and a
			// flat file sits at exactly the depth that does get scanned. So
			// WordPress listed the must-use loader as a second installable
			// plugin, and a site that installed the archive twice ended up with
			// four phantom rows that could each be activated and deleted.
			//
			// Note the scope. Asking `get_plugins( '/<dir>' )` re-roots the
			// scan and reports the loader every time, which is a true statement
			// about a directory and a false one about what WordPress lists.
			// This asks the unscoped question and filters, because that is the
			// list on the screen the bug appeared on.
			const rows = JSON.parse(
				wpEnv( [
					'run',
					'cli',
					'wp',
					'eval',
					"require_once ABSPATH . 'wp-admin/includes/plugin.php'; " +
						'echo wp_json_encode( array_keys( get_plugins() ) );',
				] ).trim()
			);

			const mine = rows.filter( ( row ) => row.startsWith( `${ plugin.installAs }/` ) );

			assert.deepEqual(
				mine,
				[ `${ plugin.installAs }/${ plugin.entry }` ],
				'WordPress must list exactly one plugin for this package'
			);

			// And WordPress's own verdict on the file, which is a different
			// question from whether it activated: `validate_plugin()` is what
			// the installer, the updater and the bulk actions all call, and it
			// answers on the header and the path rather than on whether any
			// code ran.
			const validated = wpEnv( [
				'run',
				'cli',
				'wp',
				'eval',
				"require_once ABSPATH . 'wp-admin/includes/plugin.php'; " +
					`$v = validate_plugin( '${ plugin.installAs }/${ plugin.entry }' ); ` +
					"echo is_wp_error( $v ) ? $v->get_error_message() : 'ok';",
			] ).trim();

			assert.equal(
				validated,
				'ok',
				`WordPress refused ${ plugin.installAs }: ${ validated }`
			);

		} finally {
			try {
				wpEnv( [ 'run', 'cli', 'wp', 'plugin', 'deactivate', plugin.installAs ] );
			} catch {
				// Never activated. Nothing to undo.
			}

			// rm, not `wp plugin delete`: the guard above proves this path is
			// not a mount, and a plain rm cannot be talked into following one.
			sh( `rm -rf ${ target } ${ workspace }` );

			if ( deactivateMapped ) {
				wpEnv( [ 'run', 'cli', 'wp', 'plugin', 'activate', plugin.slug ] );
			}
		}
	}
} );
