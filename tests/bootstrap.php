<?php
/**
 * PHPUnit bootstrap for the unit suite.
 *
 * @package Scanfully\Tests
 */

// Define the constants the plugin reads at runtime. Tests run without
// WordPress loaded, so the entry file is never required.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SCANFULLY_VERSION' ) ) {
	define( 'SCANFULLY_VERSION', '0.0.0-test' );
}
if ( ! defined( 'SCANFULLY_PLUGIN_FILE' ) ) {
	define( 'SCANFULLY_PLUGIN_FILE', dirname( __DIR__ ) . '/scanfully.php' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal WordPress and WooCommerce class stubs so files that `extends` core
// classes are loadable when PHPUnit's coverage processes uncovered files
// without a WordPress runtime.
require_once __DIR__ . '/stubs.php';

// yoast/wp-test-utils boots Brain Monkey + Mockery automatically via its
// TestCase base classes. No global setup beyond autoload is required.
