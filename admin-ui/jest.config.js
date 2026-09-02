/**
 * Jest, on the admin UI only.
 *
 * The default `@wordpress/scripts` configuration looks in `src/`, which in this
 * repository is PHP. Pointing Jest at `admin-ui/` keeps the two languages from
 * tripping over each other's directory names.
 */

const path = require( 'path' );

const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	rootDir: path.join( __dirname, '..' ),
	testMatch: [ '<rootDir>/admin-ui/**/*.test.js' ],
	setupFilesAfterEnv: [
		...( base.setupFilesAfterEnv || [] ),
		path.join( __dirname, 'test', 'setup.js' ),
	],
	moduleNameMapper: {
		'\\.(scss|css)$': path.join( __dirname, 'test', 'style-stub.js' ),
		'^@wordpress/components$': path.join(
			__dirname,
			'test',
			'wordpress-components.js'
		),
	},
};
