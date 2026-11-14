<?php
/**
 * Loads the PHP error monitor before Composer's autoloader is there.
 *
 * Included by the Scanfully plugin's main file while plugins load, and by
 * the must-use plugin before any plugin loads. Registers a small autoloader
 * for the plugin's classes (the same mapping as Composer's), so the error
 * monitor and what it sends with work even when a fatal error stops the
 * request before the Scanfully plugin boots.
 *
 * @package Scanfully
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SCANFULLY_ERRORS_LOADER' ) ) {
	define( 'SCANFULLY_ERRORS_LOADER', __FILE__ );

	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( 0 !== strpos( $class_name, 'Scanfully\\' ) ) {
				return;
			}
			$file = dirname( __DIR__ ) . '/' . str_replace( '\\', '/', substr( $class_name, strlen( 'Scanfully\\' ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}
