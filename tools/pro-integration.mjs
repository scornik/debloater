#!/usr/bin/env node
/**
 * Run Debloater Pro's integration suite against this plugin's wp-env.
 *
 * Pro is a separate, private repository. Its integration tests assert what Pro
 * does to a site running Debloater, so they need both plugins in one
 * WordPress — and the only WordPress either repository has is this one's.
 *
 * The mapping that puts Pro in the container is deliberately not in
 * `.wp-env.json`. wp-env can only map a directory that exists, so a mapping to
 * `../debloater-pro` there would break `wp-env start` for everyone without a
 * Pro checkout, which is everyone but us. It lives in `.wp-env.override.json`,
 * which is gitignored, and `.wp-env.override.json.dist` is the template for it.
 *
 * This script's whole job is to make the missing-mapping case say so. Running
 * the suite without it produces a PHPUnit bootstrap error about a path inside
 * a container, which is a true statement about the wrong level.
 *
 * See debloater-pro/docs/DECISIONS.md D-0065.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

const OVERRIDE = path.join( ROOT, '.wp-env.override.json' );
const TEMPLATE = path.join( ROOT, '.wp-env.override.json.dist' );

const PRO_SLUG = 'debloater-pro';
const CONTAINER_PATH = `wp-content/plugins/${ PRO_SLUG }`;

/**
 * Stop, and say what to do about it.
 *
 * @param {string[]} lines What went wrong and how to fix it.
 */
const refuse = ( lines ) => {
	process.stderr.write( `\n${ lines.join( '\n' ) }\n\n` );
	process.exit( 1 );
};

/**
 * Where the override says Pro is, or null when it does not say.
 *
 * @return {string|null} The mapped path, or null.
 */
const mappedProPath = () => {
	if ( ! fs.existsSync( OVERRIDE ) ) {
		return null;
	}

	let parsed;

	try {
		parsed = JSON.parse( fs.readFileSync( OVERRIDE, 'utf8' ) );
	} catch ( error ) {
		refuse( [
			`${ OVERRIDE } is not valid JSON:`,
			`  ${ error.message }`,
			'',
			`Start again from ${ path.basename( TEMPLATE ) }.`,
		] );
	}

	// The tests environment is the one that matters — the suite runs in
	// tests-cli — but a mapping in only one of the two is a configuration
	// somebody will spend an afternoon on, so both are required.
	const top = parsed?.mappings?.[ CONTAINER_PATH ];
	const tests = parsed?.env?.tests?.mappings?.[ CONTAINER_PATH ];

	if ( ! top || ! tests ) {
		return null;
	}

	return tests;
};

const mapped = mappedProPath();

if ( ! mapped ) {
	refuse( [
		'Debloater Pro is not mapped into this environment, so its integration',
		'tests have nothing to run against.',
		'',
		'The mapping is a template here rather than a default, because this',
		'repository is public and wp-env has to start on a machine with no Pro',
		'checkout beside it:',
		'',
		`    cp ${ path.basename( TEMPLATE ) } ${ path.basename( OVERRIDE ) }`,
		'    npm run env:start          # restart, so the mapping takes effect',
		'    npm run test:integration:pro',
		'',
		`Edit the copy if your Pro checkout is not at ../${ PRO_SLUG }.`,
		'',
		'See debloater-pro/docs/DECISIONS.md D-0065.',
	] );
}

const resolved = path.resolve( ROOT, mapped );

if ( ! fs.existsSync( path.join( resolved, 'debloater-pro.php' ) ) ) {
	refuse( [
		`${ path.basename( OVERRIDE ) } maps Pro to:`,
		`    ${ mapped }`,
		`    -> ${ resolved }`,
		'',
		'and there is no debloater-pro.php there. wp-env will create the',
		'directory rather than complain, so the container would come up with an',
		'empty plugin and the suite would fail about a missing bootstrap.',
		'',
		'Point the mapping at your Pro checkout.',
	] );
}

// Relative to the free plugin's own directory in the container, because that is
// where the WordPress test suite and the PHPUnit 9 phar are. PHPUnit resolves
// the paths inside a configuration file relative to that file, so
// `tests/Integration` in Pro's config means Pro's.
const command = [
	'run',
	'tests-cli',
	'--env-cwd=wp-content/plugins/debloater',
	'php',
	'tools/phpunit-9.phar',
	'-c',
	`../${ PRO_SLUG }/phpunit-wp.xml.dist`,
	...process.argv.slice( 2 ),
];

// wp-env's own bin, run directly. `npx wp-env` would need a shell on Windows,
// and passing an argument array through a shell concatenates rather than
// escapes it -- which Node deprecated for exactly the reason it sounds like.
const wpEnv = path.join(
	ROOT,
	'node_modules',
	'@wordpress',
	'env',
	'bin',
	'wp-env'
);

if ( ! fs.existsSync( wpEnv ) ) {
	refuse( [
		'@wordpress/env is not installed. Run `npm ci` first.',
	] );
}

try {
	execFileSync( process.execPath, [ wpEnv, ...command ], {
		cwd: ROOT,
		stdio: 'inherit',
	} );
} catch ( error ) {
	process.exit( typeof error.status === 'number' ? error.status : 1 );
}
