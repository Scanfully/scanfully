<?php
/**
 * The Controller class file.
 *
 * @package Scanfully
 */

namespace Scanfully\Options;

/**
 * The options class handles everything related to the plugin options.
 */
class Controller {

	/**
	 * The options key
	 *
	 * @var string
	 */
	private static string $db_prefix = 'scanfully_connect_';

	/**
	 * Get options helper
	 *
	 * @return Options
	 */
	public static function get_options(): Options {
		return apply_filters(
			'scanfully_options',
			new Options(
				self::get_option( 'is_connected' ) === 'yes',
				self::get_option( 'site_id' ),
				self::get_option( 'access_token' ),
				self::get_option( 'refresh_token' ),
				self::get_option( 'expires' ),
				self::get_option( 'last_used' ),
				self::get_option( 'date_connected' )
			)
		);
	}

	/**
	 * WordPress get_option wrapper
	 *
	 * @param  string $name The name of the option.
	 *
	 * @return string
	 */
	public static function get_option( string $name ): string {
		return apply_filters( 'scanfully_option', get_option( self::$db_prefix . $name, ) );
	}

	/**
	 * Save options to WP options table
	 *
	 * @param  Options $options
	 *
	 * @return void
	 */
	public static function set_options( Options $options ): void {
		self::set_option( 'is_connected', $options->is_connected ? 'yes' : 'no' );
		self::set_option( 'site_id', $options->site_id );
		self::set_option( 'access_token', $options->access_token );
		self::set_option( 'refresh_token', $options->refresh_token );
		self::set_option( 'expires', $options->expires );
		self::set_option( 'last_used', $options->last_used );
		self::set_option( 'date_connected', $options->date_connected );
	}

	/**
	 * Set an option
	 *
	 * @param  string $name
	 * @param  string $value
	 *
	 * @return void
	 */
	public static function set_option( string $name, string $value ): void {
		update_option( self::$db_prefix . $name, $value );
	}

	/**
	 * Clear all options
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::$db_prefix . 'is_connected' );
		delete_option( self::$db_prefix . 'site_id' );
		delete_option( self::$db_prefix . 'access_token' );
		delete_option( self::$db_prefix . 'refresh_token' );
		delete_option( self::$db_prefix . 'expires' );
		delete_option( self::$db_prefix . 'last_used' );
		delete_option( self::$db_prefix . 'date_connected' );

		do_action( 'scanfully_options_cleared' );
	}
}