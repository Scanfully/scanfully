<?php
/**
 * PHPStan bootstrap.
 *
 * Defines the plugin constants that `scanfully.php` sets at runtime so static
 * analysis can resolve `SCANFULLY_*` references in `src/`. This file is loaded
 * by PHPStan only and has no effect at runtime.
 *
 * @package Scanfully\Tests
 */

if ( ! defined( 'SCANFULLY_VERSION' ) ) {
	define( 'SCANFULLY_VERSION', '0.0.0' );
}
if ( ! defined( 'SCANFULLY_PLUGIN_FILE' ) ) {
	define( 'SCANFULLY_PLUGIN_FILE', dirname( __DIR__ ) . '/scanfully.php' );
}

// WordPress defines these in wp-config.php / wp-settings.php at runtime (the
// PHPStan WordPress extension already covers ABSPATH). They are also listed
// under `dynamicConstantNames` in phpstan.neon.dist so the values here are
// not treated as fixed.
if ( ! defined( 'WP_CACHE' ) ) {
	define( 'WP_CACHE', false );
}
if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
	define( 'WP_MEMORY_LIMIT', '40M' );
}
if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
	define( 'WP_MAX_MEMORY_LIMIT', '256M' );
}
