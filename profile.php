<?php
declare( ticks=1 );


//ini_set( "log_errors", 1 );
//ini_set( "error_log", $_SERVER['DOCUMENT_ROOT'] . "/wp-content/debug.log" );

// WordPress can't be loaded more than once
if ( function_exists( 'add_filter' ) ) {
	echo 'already ran';
	exit;
}

// get request data
$request_json = file_get_contents( 'php://input' );
$request_data = json_decode( $request_json, true );
if ( ! is_array( $request_data ) || empty( $request_data ) || ! isset( $request_data['url'] ) || ! isset( $request_data['type'] ) ) {
	echo 'invalid request';
	exit;
}

// check type
$type = $request_data['type'] == 'hooks' ? 'hooks' : 'ticks';

// Mock $_SERVER variables0
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = $request_data['url']; // @todo: validate this
$_SERVER['QUERY_STRING']   = ''; // @todo: parse query string from $request_data['url'] and set here

// load Scanfully classes, not autoloading to avoid conflicts / our autoloader trying to load other classes
require __DIR__ . '/src/Profiler/Utils.php';
require __DIR__ . '/src/Profiler/HookProfiler.php';
require __DIR__ . '/src/Profiler/Ticks/StreamWrapper.php';
require __DIR__ . '/src/Profiler/Data/ResultDataInterface.php';
require __DIR__ . '/src/Profiler/Data/ProfilingInterface.php';
require __DIR__ . '/src/Profiler/Data/Profiling.php';
require __DIR__ . '/src/Profiler/Data/ResultData.php';
require __DIR__ . '/src/Profiler/Ticks/Tick.php';
require __DIR__ . '/src/Profiler/Ticks/TickProfiler.php';
require __DIR__ . '/src/Profiler/Data/Callback.php';
require __DIR__ . '/src/Profiler/Data/Hook.php';
require __DIR__ . '/src/Profiler/Data/Core.php';
require __DIR__ . '/src/Profiler/Data/Plugin.php';
require __DIR__ . '/src/Profiler/Data/Theme.php';
require __DIR__ . '/src/Profiler/Data/StackItem.php';
require __DIR__ . '/src/Profiler/Data/Stage.php';

// load wp-config.php manually
eval( \Scanfully\Profiler\Utils::get_wp_config_code() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

// setup the required constants
\Scanfully\Profiler\Utils::setup_required_constants();

if ( $type == "hooks" ) {
	// load Scanfully Profiler
	$hook_profiler = new \Scanfully\Profiler\HookProfiler();

	// load our custom wp-config.php manually
//	eval( \Scanfully\Profiler\Utils::get_wp_config_code() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

	// handle constants
	$hook_profiler->check_constants();

	// start listening to hooks
	$hook_profiler->listen();
} elseif ( $type == "ticks" ) {
	// stream wrapper
	\Scanfully\Profiler\Ticks\StreamWrapper::start();

	// setup the tick profiler
	$tick_profiler = new \Scanfully\Profiler\Ticks\TickProfiler();
	$tick_profiler->start();
} else {
	die( 'Invalid type' );
}

// --------------- Start the WordPress simulation ---------------
// do WP 'bootstrap',
require ABSPATH . 'wp-settings.php';

// Set up the WordPress query.
wp();

// Define the template related constants.
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


// json response
header( 'Content-Type: application/json' );

if ( $type == "hooks" ) {
	echo json_encode( $hook_profiler->get_data() );
} elseif ( $type == "ticks" ) {
	// stop the tick profiler
	$tick_profiler->shutdown();

	echo json_encode( $tick_profiler->get_data() );
}

// bye
exit;