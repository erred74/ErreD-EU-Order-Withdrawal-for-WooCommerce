<?php
/**
 * Bootstrap for the integration test suite.
 *
 * Runs inside the wp-env "tests" container, where a real WordPress + WooCommerce install lives at
 * /var/www/html. Loading wp-load.php gives the tests a live $wpdb and the real dbDelta path, so the
 * schema and write-once/duplicate-guard invariants are exercised against an actual MySQL database.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

$recesso_dig_wp_load = getenv( 'WP_LOAD_PATH' );
if ( false === $recesso_dig_wp_load || '' === $recesso_dig_wp_load ) {
	$recesso_dig_wp_load = '/var/www/html/wp-load.php';
}

if ( ! is_readable( $recesso_dig_wp_load ) ) {
	fwrite( STDERR, "Cannot locate wp-load.php at {$recesso_dig_wp_load}. Run integration tests inside wp-env (tests-cli).\n" );
	exit( 1 );
}

require $recesso_dig_wp_load;

// Ensure the plugin classes are autoloadable even if the plugin is not network-active in this site.
require dirname( __DIR__ ) . '/vendor/autoload.php';
