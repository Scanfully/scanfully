<?php
/**
 * Integration test bootstrap.
 *
 * Loads a real WordPress runtime so the integration suite can assert against
 * actual hooks, the REST server, and the booted plugin. Intended to run inside
 * the wp-env `tests-cli` container, where WordPress lives at `WP_ABSPATH`
 * (default `/var/www/html`) and the plugin (with its Composer `vendor/`) is
 * mapped into `wp-content/plugins/<checkout folder name>`.
 *
 * The fast unit suite keeps using `tests/bootstrap.php`; this file is only
 * referenced by `phpunit-integration.xml.dist`.
 *
 * @package Scanfully\Tests
 */

$scanfully_abspath = getenv( 'WP_ABSPATH' );
if ( ! is_string( $scanfully_abspath ) || '' === $scanfully_abspath ) {
	$scanfully_abspath = '/var/www/html';
}
$scanfully_abspath = rtrim( $scanfully_abspath, '/' ) . '/';

$scanfully_wp_load = $scanfully_abspath . 'wp-load.php';

if ( ! is_readable( $scanfully_wp_load ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped
	fwrite(
		STDERR,
		"Integration bootstrap could not find wp-load.php at {$scanfully_wp_load}.\n"
		. "Run this suite inside wp-env: `npm run test:integration`.\n"
	);
	exit( 1 );
}

// Boot WordPress with the plugin active.
require_once $scanfully_wp_load;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
