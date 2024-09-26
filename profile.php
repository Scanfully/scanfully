<?php

// WordPress can't be loaded more than once
if ( function_exists( 'add_filter' ) ) {
	echo 'already ran';
	return;
}

// load Scanfully autoloader
require __DIR__ . '/vendor/autoload.php';

// load Scanfully Profiler
$profiler = new \Scanfully\Performance\Profiler();
$profiler->listen();


/*
add_wp_hook('init', function() {
	error_log('loaded from profile.php');
});
*/

// Set up the $_SERVER global
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'scanfully-plugin.test';
$_SERVER['SERVER_NAME'] = 'scanfully-plugin.test';
$_SERVER['REQUEST_URI'] = '/nee/';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['QUERY_STRING'] = '';

//var_dump($_SERVER);
//exit;

// do WP 'bootstrap',
require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';

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

echo 'done';