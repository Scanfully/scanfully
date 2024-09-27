<?php

// WordPress can't be loaded more than once
if ( function_exists( 'add_filter' ) ) {
	echo 'already ran';
	exit;
}

// get request data
$request_json = file_get_contents( 'php://input' );
$request_data = json_decode( $request_json, true );
if ( ! is_array( $request_data ) || empty( $request_data ) || ! isset( $request_data['url'] ) ) {
	echo 'invalid request';
	exit;
}

// Mock $_SERVER variables
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = $request_data['url']; // @todo: validate this
$_SERVER['QUERY_STRING']   = ''; // @todo: parse query string from $request_data['url'] and set here

// load Scanfully autoloader so we can use profiler
require __DIR__ . '/vendor/autoload.php';

// load Scanfully Profiler
$profiler = new \Scanfully\Profiler\Profiler();

// load our custom wp-config.php manually
eval( \Scanfully\Profiler\Utils::get_wp_config_code() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

// handle constants
$profiler->handle_constants();

// start listening
$profiler->listen();

exit;

// --------------- Start the WordPress simulation ---------------

// do WP 'bootstrap',
//require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
require ABSPATH . 'wp-settings.php';

// Set up the WordPress query.
wp();

define( 'WP_USE_THEMES', true );

// Template is normally loaded in global scope, so we need to replicate
foreach ( $GLOBALS as $key => $value ) {
	global ${$key}; // phpcs:ignore
	// PHPCompatibility.PHP.ForbiddenGlobalVariableVariable.NonBareVariableFound -- Syntax is updated to compatible with php 5 and 7.
}

// Load the theme template.
ob_start();
require_once ABSPATH . WPINC . '/template-loader.php';
ob_get_clean();

// --------------- End the WordPress simulation ---------------

// stop listening, gather info, yadayadayadayada
echo 'done';