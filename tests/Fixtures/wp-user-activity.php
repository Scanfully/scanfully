<?php
/**
 * Stand-in for WP User Activity (2.3.0 and later): the function it declares
 * to name its post type, for tests that need the plugin active.
 *
 * @package Scanfully\Tests
 */

if ( ! function_exists( 'wp_user_activity_get_post_type' ) ) {
	/**
	 * The post type WP User Activity saves its log in.
	 *
	 * @return string
	 */
	function wp_user_activity_get_post_type() {
		return 'activity';
	}
}
