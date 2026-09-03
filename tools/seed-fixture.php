<?php
/**
 * Seed the wp-env development site with the fixture data the scanners measure.
 *
 * Integration tests seed their own data inside a transaction, so this script is
 * for the *development* site: the one you open in a browser to see what the
 * dashboard actually looks like against a site with something wrong with it.
 *
 * Run it with:
 *
 *     npx wp-env run cli --env-cwd=wp-content/plugins/wp-debloat wp eval-file tools/seed-fixture.php
 *
 * It is idempotent in the sense that running it twice seeds twice; it is a
 * fixture generator, not a migration. Nothing here runs on a real site: the
 * script refuses to do anything unless WP_ENVIRONMENT_TYPE says local.
 *
 * @package WPDebloat
 */

/*
 * No `declare( strict_types = 1 )` here on purpose. `wp eval-file` runs a script
 * through eval(), where a declare must be the very first statement of the file
 * and therefore cannot be — the file fatals before it does anything. Every other
 * PHP file in this repository declares strict types; these two are eval'd rather
 * than included, which is the whole difference.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error(
		'This script creates deliberately messy data and will only run on a local environment. '
		. 'WP_ENVIRONMENT_TYPE is currently "' . wp_get_environment_type() . '".'
	);
}

global $wpdb;

/**
 * How much of each thing to create. Small enough to run in seconds, large
 * enough that the counts are obviously not noise.
 */
$wpdebloat_seed = array(
	'posts'          => 12,
	'revisions_each' => 4,
	'spam_comments'  => 25,
	'expired'        => 40,
	'live'           => 5,
	'orphan_meta'    => 30,
	'trashed'        => 6,
);

WP_CLI::log( 'Seeding posts and revisions…' );

$wpdebloat_post_ids = array();

for ( $i = 0; $i < $wpdebloat_seed['posts']; $i++ ) {
	$post_id = wp_insert_post(
		array(
			'post_title'   => sprintf( 'Fixture post %d', $i + 1 ),
			'post_content' => 'Revision 0.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);

	if ( is_wp_error( $post_id ) || 0 === $post_id ) {
		continue;
	}

	$wpdebloat_post_ids[] = $post_id;

	for ( $r = 1; $r <= $wpdebloat_seed['revisions_each']; $r++ ) {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => sprintf( 'Revision %d.', $r ),
			)
		);
	}
}

WP_CLI::log( sprintf( 'Created %d posts.', count( $wpdebloat_post_ids ) ) );

WP_CLI::log( 'Trashing some of them…' );

foreach ( array_slice( $wpdebloat_post_ids, 0, $wpdebloat_seed['trashed'] ) as $post_id ) {
	wp_trash_post( $post_id );
}

WP_CLI::log( 'Seeding spam comments…' );

foreach ( range( 1, $wpdebloat_seed['spam_comments'] ) as $n ) {
	wp_insert_comment(
		array(
			'comment_post_ID'      => $wpdebloat_post_ids[ array_rand( $wpdebloat_post_ids ) ],
			'comment_author'       => 'Definitely Not A Bot',
			'comment_author_email' => sprintf( 'bot%d@example.invalid', $n ),
			'comment_content'      => 'Fixture spam.',
			'comment_approved'     => 'spam',
		)
	);
}

WP_CLI::log( 'Seeding transients…' );

foreach ( range( 1, $wpdebloat_seed['live'] ) as $n ) {
	set_transient( sprintf( 'wpdebloat_fixture_live_%d', $n ), 'still useful', DAY_IN_SECONDS );
}

// Expired transients have to be written directly: set_transient() will not
// accept a timeout in the past, and get_transient() deletes what it finds
// expired, so there would be nothing left to measure.
foreach ( range( 1, $wpdebloat_seed['expired'] ) as $n ) {
	$name = sprintf( 'wpdebloat_fixture_stale_%d', $n );

	$wpdb->insert(
		$wpdb->options,
		array(
			'option_name'  => '_transient_' . $name,
			'option_value' => 'long past its usefulness',
			'autoload'     => 'off',
		)
	);
	$wpdb->insert(
		$wpdb->options,
		array(
			'option_name'  => '_transient_timeout_' . $name,
			'option_value' => (string) ( time() - DAY_IN_SECONDS ),
			'autoload'     => 'off',
		)
	);
}

WP_CLI::log( 'Seeding orphan post meta…' );

foreach ( range( 1, $wpdebloat_seed['orphan_meta'] ) as $n ) {
	$wpdb->insert(
		$wpdb->postmeta,
		array(
			'post_id'    => 900000 + $n,
			'meta_key'   => '_wpdebloat_fixture_orphan',
			'meta_value' => 'left behind by a plugin that is no longer installed',
		)
	);
}

WP_CLI::log( 'Seeding a large autoloaded option…' );

update_option( 'wpdebloat_fixture_cache', str_repeat( 'x', 512000 ), true );

WP_CLI::log( 'Seeding cron events…' );

add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- A sub-minute schedule is the point of the fixture.
	static function ( $schedules ) {
		$schedules['wpdebloat_fixture_30s'] = array(
			'interval' => 30,
			'display'  => 'Every 30 seconds (fixture)',
		);

		return $schedules;
	}
);

if ( ! wp_next_scheduled( 'wpdebloat_fixture_sync' ) ) {
	wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wpdebloat_fixture_30s', 'wpdebloat_fixture_sync' );
}

foreach ( range( 1, 15 ) as $n ) {
	$hook = sprintf( 'wpdebloat_fixture_orphan_%d', $n );

	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $hook );
	}
}

WP_CLI::log( 'Creating extra administrators…' );

foreach ( range( 1, 3 ) as $n ) {
	$login = sprintf( 'wpdebloat_fixture_admin_%d', $n );

	if ( ! username_exists( $login ) ) {
		wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.invalid',
				'user_pass'  => wp_generate_password( 32 ),
				'role'       => 'administrator',
			)
		);
	}
}

wp_cache_flush();

WP_CLI::success( 'Fixture data seeded. Run a scan to see it.' );
