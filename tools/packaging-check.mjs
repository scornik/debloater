#!/usr/bin/env node
/**
 * Run the packaging suite locally, and give the toolchain back afterwards.
 *
 * `composer check:packaging`.
 *
 * ## Why this needs a script at all
 *
 * The release archive must carry a production autoloader: one that does not
 * name `Tests\` or any dev package. `scripts/plugin-zip.mjs` refuses to build
 * otherwise, which is right — a zip that autoloads a test namespace is a zip
 * that ships a promise about files it does not contain.
 *
 * So the packaging suite cannot run against a development install, and the
 * obvious way to satisfy it — `composer install --no-dev` — deletes PHPUnit,
 * PHPCS and PHPStan from the tree. That was done by hand once during phase 19c
 * and cost two Composer reinstalls, one of which timed out. A check whose cost
 * is "lose your toolchain for ten minutes" is a check that gets skipped, and
 * this one had been skipped for three commits.
 *
 * ## What it does instead
 *
 * `composer dump-autoload --no-dev --classmap-authoritative` regenerates the
 * autoloader **without touching the installed packages**. Every dev tool stays
 * where it is and keeps working; only the nine generated files under
 * `vendor/` change, and those are the only files under `vendor/` that the
 * archive contains at all.
 *
 * The two are byte-identical, which was verified rather than assumed: building
 * the zip after `install --no-dev --classmap-authoritative` and after
 * `dump-autoload --no-dev --classmap-authoritative` produced the same sha256
 * for all five shipped autoloader files.
 *
 * The dev autoloader is restored in a `finally`, and the restore is checked. If
 * it ever fails, this says so loudly and tells you the one command that fixes
 * it, rather than leaving a tree whose next `phpunit` fails for a reason with
 * no obvious connection to packaging.
 *
 * ## When the `finally` does not get to run
 *
 * It cannot always. Ctrl-C, a killed terminal, or piping this into `head` —
 * which closes the pipe and takes the process with it — all end the run before
 * any cleanup. That happened on the first real use of this script and left the
 * tree with a production autoloader and no obvious sign of why.
 *
 * So the restore does not depend only on the `finally`. A tree with dev
 * packages installed and an autoloader that names none of them is a tree
 * something interrupted, and that state is detected and repaired on the next
 * run before anything else happens. The check is cheap and the repair is one
 * command, so it is always safe to just run this again.
 *
 * ## Not a scratch directory
 *
 * A scratch copy would be the tidier answer, and it was rejected on cost: the
 * build step needs `node_modules`, so a genuinely isolated run means a second
 * npm install of several hundred megabytes for a check meant to take a minute.
 * CI does have that isolation — every runner is a fresh checkout — and the
 * `package` job is where the pristine-environment version of this runs.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const PSR4 = path.join( ROOT, 'vendor', 'composer', 'autoload_psr4.php' );

/**
 * Run a command, letting its output through.
 *
 * @param {string}   file What to run.
 * @param {string[]} args Arguments.
 */
const run = ( file, args ) => {
	execFileSync( file, args, { cwd: ROOT, stdio: 'inherit' } );
};

/**
 * Composer, however this machine has it.
 *
 * There is no native PHP or Composer here — see docs/DECISIONS.md D-0003 — so
 * a local `composer` is the exception rather than the rule, and Docker is the
 * fallback rather than the other way around.
 *
 * @param {string[]} args Composer arguments.
 */
const composer = ( args ) => {
	try {
		run( 'composer', [ ...args, '--no-interaction' ] );

		return;
	} catch ( error ) {
		if ( error.code !== 'ENOENT' ) {
			throw error;
		}
	}

	run( 'docker', [
		'run',
		'--rm',
		'-e',
		'COMPOSER_PROCESS_TIMEOUT=0',
		'-v',
		`${ ROOT }:/app`,
		'-w',
		'/app',
		'--entrypoint',
		'sh',
		'composer:2',
		'-c',
		`composer ${ [ ...args, '--no-interaction' ].join( ' ' ) }`,
	] );
};

/**
 * Whether the autoloader currently names a development namespace.
 *
 * @return {boolean} True when this is a dev autoloader.
 */
const autoloaderIsDev = () =>
	fs.existsSync( PSR4 ) && fs.readFileSync( PSR4, 'utf8' ).includes( 'Tests\\\\' );

/**
 * Whether the dev packages are installed.
 *
 * PHPUnit's binary, because it is the one every other check needs and the one
 * whose absence is unambiguous.
 *
 * @return {boolean} True when this is a development install.
 */
const devPackagesInstalled = () =>
	fs.existsSync( path.join( ROOT, 'vendor', 'bin', 'phpunit' ) ) ||
	fs.existsSync( path.join( ROOT, 'vendor', 'bin', 'phpunit.bat' ) );

// Dev packages present, dev autoloader absent: an earlier run of this script
// was interrupted between switching and switching back. Repair it before doing
// anything, so that running this again is always the fix.
if ( devPackagesInstalled() && ! autoloaderIsDev() ) {
	process.stdout.write(
		'\nAn earlier run left the production autoloader in place. Restoring it first.\n'
	);

	composer( [ 'dump-autoload' ] );

	if ( ! autoloaderIsDev() ) {
		process.stderr.write(
			'\nThe development autoloader could not be restored. Run:\n\n' +
				'    composer dump-autoload\n\n'
		);
		process.exit( 1 );
	}
}

let switched = false;

try {
	if ( autoloaderIsDev() ) {
		process.stdout.write( '\n> production autoloader (packages untouched)\n' );

		composer( [ 'dump-autoload', '--no-dev', '--classmap-authoritative' ] );

		switched = true;
	}

	process.stdout.write( '\n> npm run build\n' );
	run( process.execPath, [
		path.join( ROOT, 'node_modules', '@wordpress', 'scripts', 'bin', 'wp-scripts.js' ),
		'build',
		'--webpack-src-dir=admin-ui/src',
		'--output-path=build',
	] );

	process.stdout.write( '\n> tests/Packaging\n' );
	run( process.execPath, [ '--test', path.join( ROOT, 'tests', 'Packaging', 'zip.test.mjs' ) ] );
} finally {
	if ( switched ) {
		process.stdout.write( '\n> development autoloader\n' );

		try {
			composer( [ 'dump-autoload' ] );
		} catch ( error ) {
			process.stderr.write(
				`\nThe development autoloader could not be restored: ${ error.message }\n\n` +
					'PHPUnit, PHPCS and PHPStan will not load until it is. Run:\n\n' +
					'    composer dump-autoload\n\n'
			);

			process.exitCode = 1;
		}

		if ( ! autoloaderIsDev() ) {
			process.stderr.write(
				'\nThe autoloader is still the production one. Run:\n\n' +
					'    composer dump-autoload\n\n'
			);

			process.exitCode = 1;
		}
	}
}
